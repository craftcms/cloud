<?php

declare(strict_types=1);

use CraftCms\Cms\Config\GeneralConfig;
use Illuminate\Container\Container;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;

const CLOUD_SERVER_KEYS = [
    'CRAFT_CLOUD_PROJECT_ID',
    'CRAFT_CLOUD_ENVIRONMENT_ID',
    'CRAFT_CLOUD_REGION',
    'AWS_REGION',
    'CRAFT_CLOUD_ACCESS_KEY',
    'CRAFT_CLOUD_ACCESS_SECRET',
    'CRAFT_CLOUD_ACCESS_TOKEN',
    'CRAFT_CLOUD_STORAGE_ENDPOINT',
    'CRAFT_CLOUD_CDN_BASE_URL',
];

const LOCAL_DISK = [
    'driver' => 'local',
    'root' => '/srv/public/uploads',
    'url' => '/uploads',
    'throw' => true,
    'asset_transformer' => 'craft-cloud',
];

beforeEach(function (): void {
    $this->savedServer = array_intersect_key($_SERVER, array_flip(CLOUD_SERVER_KEYS));

    foreach (CLOUD_SERVER_KEYS as $key) {
        unset($_SERVER[$key]);
    }

    $this->app->singleton('filesystem', static fn(Container $app) => new FilesystemManager($app));
    $this->app->singleton(GeneralConfig::class, GeneralConfig::create(...));
    $this->config->set('filesystems.disks', [
        'craft-cloud' => LOCAL_DISK,
        // How projects upgraded from Craft 5 keep their filesystem handles
        'images' => ['driver' => 'scoped', 'disk' => 'craft-cloud', 'prefix' => 'images'],
    ]);
});

afterEach(function (): void {
    foreach (CLOUD_SERVER_KEYS as $key) {
        unset($_SERVER[$key]);
    }

    $_SERVER = [...$_SERVER, ...$this->savedServer];
});

function on_cloud(): void
{
    $_SERVER = [
        ...$_SERVER,
        'CRAFT_CLOUD' => '1',
        'CRAFT_CLOUD_PROJECT_ID' => 'project-bucket',
        'CRAFT_CLOUD_ENVIRONMENT_ID' => 'environment-id',
        'CRAFT_CLOUD_REGION' => 'us-west-2',
    ];
}

it('leaves the project’s disks and settings alone outside Craft Cloud', function (): void {
    $this->registerProvider();

    expect($this->config->get('filesystems.disks.craft-cloud'))
        ->toBe(LOCAL_DISK)
        ->and($this->config->get('filesystems.disks.craft-cloud-tmp'))
        ->toBeNull()
        ->and($this->app->make(GeneralConfig::class))
        ->uploadSessionDisk->toBeNull()
        ->tempAssetUploadDisk->toBeNull();
});

it('puts the craft-cloud disk in the project bucket, under the environment, with CDN URLs', function (): void {
    on_cloud();
    $credentials = [bin2hex(random_bytes(8)), bin2hex(random_bytes(8)), bin2hex(random_bytes(8))];
    [
        $_SERVER['CRAFT_CLOUD_ACCESS_KEY'],
        $_SERVER['CRAFT_CLOUD_ACCESS_SECRET'],
        $_SERVER['CRAFT_CLOUD_ACCESS_TOKEN'],
    ] = $credentials;
    $this->registerProvider();

    $disk = Storage::disk('craft-cloud');
    $sdkCredentials = $disk->getClient()->getCredentials()->wait();
    // A volume with its own subpath, on a disk scoped for an upgraded filesystem handle
    $volume = Storage::build(['driver' => 'scoped', 'disk' => 'images', 'prefix' => 'volume']);

    expect($disk)
        ->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($disk->getConfig())
        ->toMatchArray(['bucket' => 'project-bucket', 'region' => 'us-west-2', 'visibility' => 'private'])
        ->and($disk->path('photo.jpg'))
        ->toBe('environment-id/assets/photo.jpg')
        ->and($disk->url('photo.jpg'))
        ->toBe('https://cdn.craft.cloud/environment-id/assets/photo.jpg')
        ->and($volume->path('photo.jpg'))
        ->toBe('environment-id/assets/images/volume/photo.jpg')
        ->and($volume->url('photo.jpg'))
        ->toBe('https://cdn.craft.cloud/environment-id/assets/images/volume/photo.jpg')
        ->and([
            $sdkCredentials->getAccessKeyId(),
            $sdkCredentials->getSecretKey(),
            $sdkCredentials->getSecurityToken(),
        ])
        ->toBe($credentials);
});

it('keeps the project’s other craft-cloud disk options on Craft Cloud', function (): void {
    on_cloud();
    $this->registerProvider();

    expect($this->config->get('filesystems.disks.craft-cloud'))->toMatchArray([
        'driver' => 's3',
        'throw' => true,
        'asset_transformer' => 'craft-cloud',
    ]);
});

it('uses a storage endpoint and CDN base URL when they’re set', function (): void {
    on_cloud();
    $_SERVER['CRAFT_CLOUD_STORAGE_ENDPOINT'] = 'http://minio:9000';
    $_SERVER['CRAFT_CLOUD_CDN_BASE_URL'] = 'https://cdn.example.test/';
    $this->registerProvider();

    $disk = Storage::disk('craft-cloud');

    expect($disk->getConfig())
        ->toMatchArray(['endpoint' => 'http://minio:9000', 'use_path_style_endpoint' => true])
        ->not
        ->toHaveKeys(['key', 'secret', 'token'])
        ->and($disk->url('photo.jpg'))
        ->toBe('https://cdn.example.test/environment-id/assets/photo.jpg');
});

it('stages upload sessions and temporary asset uploads in the bucket on Craft Cloud', function (): void {
    on_cloud();
    $_SERVER['CRAFT_CLOUD_ACCESS_KEY'] = bin2hex(random_bytes(8));
    $_SERVER['CRAFT_CLOUD_STORAGE_ENDPOINT'] = 'http://minio:9000';
    $this->registerProvider();

    $config = $this->app->make(GeneralConfig::class);
    $uploads = Storage::disk($config->getUploadSessionDisk());
    // Craft copies staged uploads within S3 only when both disks have the same credentials and endpoint
    $account = static fn(array $disk): array => array_intersect_key($disk, array_flip([
        'bucket',
        'key',
        'secret',
        'token',
        'endpoint',
    ]));

    expect($uploads)
        ->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($uploads->path('upload-sessions/1/file'))
        ->toBe('environment-id/storage/tmp/upload-sessions/1/file')
        ->and(Storage::disk($config->getTempAssetUploadDisk())->path('user_1/photo.jpg'))
        ->toBe('environment-id/storage/tmp/user_1/photo.jpg')
        ->and($account($uploads->getConfig()))
        ->toBe($account(Storage::disk('craft-cloud')->getConfig()));
});

it('leaves upload disks the project has chosen alone', function (): void {
    on_cloud();
    $this->app->singleton(GeneralConfig::class, static fn() => GeneralConfig::create()
        ->uploadSessionDisk('project-uploads')
        ->tempAssetUploadDisk('project-temp'));
    $this->registerProvider();

    expect($this->app->make(GeneralConfig::class))
        ->getUploadSessionDisk()
        ->toBe('project-uploads')
        ->getTempAssetUploadDisk()
        ->toBe('project-temp');
});

it('doesn’t configure disks on Craft Cloud without a bucket and environment', function (string $missing): void {
    on_cloud();
    unset($_SERVER[$missing]);
    $this->registerProvider();

    expect($this->config->get('filesystems.disks.craft-cloud'))
        ->toBe(LOCAL_DISK)
        ->and($this->app->make(GeneralConfig::class)->uploadSessionDisk)
        ->toBeNull();
})->with(['CRAFT_CLOUD_PROJECT_ID', 'CRAFT_CLOUD_ENVIRONMENT_ID']);
