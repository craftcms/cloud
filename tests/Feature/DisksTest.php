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

const CLOUD_BUCKET = [
    'CRAFT_CLOUD_PROJECT_ID' => 'project-bucket',
    'CRAFT_CLOUD_ENVIRONMENT_ID' => 'environment-id',
    'CRAFT_CLOUD_REGION' => 'us-west-2',
];

beforeEach(function (): void {
    $this->savedServer = array_intersect_key($_SERVER, array_flip(CLOUD_SERVER_KEYS));

    foreach (CLOUD_SERVER_KEYS as $key) {
        unset($_SERVER[$key]);
    }

    $this->app->singleton('filesystem', static fn(Container $app) => new FilesystemManager($app));
    $this->app->singleton(GeneralConfig::class, GeneralConfig::create(...));
    $this->config->set('filesystems.disks', [
        'images' => ['driver' => 'craft-cloud', 'prefix' => 'images'],
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
    $_SERVER = [...$_SERVER, 'CRAFT_CLOUD' => '1', ...CLOUD_BUCKET];
}

it('puts craft-cloud disks in the project bucket, under the environment, with CDN URLs', function (): void {
    on_cloud();
    $credentials = [bin2hex(random_bytes(8)), bin2hex(random_bytes(8)), bin2hex(random_bytes(8))];
    [
        $_SERVER['CRAFT_CLOUD_ACCESS_KEY'],
        $_SERVER['CRAFT_CLOUD_ACCESS_SECRET'],
        $_SERVER['CRAFT_CLOUD_ACCESS_TOKEN'],
    ] = $credentials;
    $this->registerProvider();

    $disk = Storage::disk('images');
    $sdkCredentials = $disk->getClient()->getCredentials()->wait();
    // How core applies a volume's subpath
    $volume = Storage::build(['driver' => 'scoped', 'disk' => 'images', 'prefix' => 'volume']);

    expect($disk)
        ->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($disk->getConfig())
        ->toMatchArray(['bucket' => 'project-bucket', 'region' => 'us-west-2', 'visibility' => 'private'])
        ->and($disk->path('photo.jpg'))
        ->toBe('environment-id/assets/images/photo.jpg')
        ->and($disk->url('photo.jpg'))
        ->toBe('https://cdn.craft.cloud/environment-id/assets/images/photo.jpg')
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

it('works outside Craft Cloud with a bucket, storage endpoint and CDN base URL', function (): void {
    $_SERVER = [
        ...$_SERVER,
        ...CLOUD_BUCKET,
        'CRAFT_CLOUD_STORAGE_ENDPOINT' => 'http://minio:9000',
        'CRAFT_CLOUD_CDN_BASE_URL' => 'https://cdn.example.test/',
    ];
    $this->registerProvider();

    $disk = Storage::disk('images');

    expect($disk)
        ->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($disk->getConfig())
        ->toMatchArray(['endpoint' => 'http://minio:9000', 'use_path_style_endpoint' => true])
        ->not
        ->toHaveKeys(['key', 'secret', 'token'])
        ->and($disk->url('photo.jpg'))
        ->toBe('https://cdn.example.test/environment-id/assets/images/photo.jpg');
});

it('says which variables are missing when a disk is resolved without a bucket', function (): void {
    // Booting is fine; only resolving the disk throws
    $this->registerProvider();
    $_SERVER['AWS_REGION'] = 'us-west-2';

    expect(static fn() => Storage::disk('images'))
        ->toThrow(
            InvalidArgumentException::class,
            'The `craft-cloud` disk driver needs CRAFT_CLOUD_PROJECT_ID, CRAFT_CLOUD_ENVIRONMENT_ID to be set.',
        );
});

it('keeps the disk’s other options, and its root within the environment', function (): void {
    on_cloud();
    $this->config->set('filesystems.disks.storage', [
        'driver' => 'craft-cloud',
        'root' => '/storage/',
        'bucket' => 'other-bucket',
        'url' => 'https://other.example.test',
        'visibility' => 'public',
        'throw' => true,
        'asset_transformer' => 'craft-cloud',
    ]);
    $this->registerProvider();

    $disk = Storage::disk('storage');

    expect($disk->getConfig())
        ->toMatchArray([
            'bucket' => 'project-bucket',
            'visibility' => 'public',
            'throw' => true,
            'asset_transformer' => 'craft-cloud',
        ])
        ->and($disk->url('debug.log'))
        ->toBe('https://cdn.craft.cloud/environment-id/storage/debug.log');
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
        ->toBe($account(Storage::disk('images')->getConfig()));
});

it('leaves upload disks to Craft outside Craft Cloud', function (): void {
    $_SERVER = [...$_SERVER, ...CLOUD_BUCKET];
    $this->registerProvider();

    expect($this->config->get('filesystems.disks.craft-cloud-tmp'))
        ->toBeNull()
        ->and($this->app->make(GeneralConfig::class))
        ->uploadSessionDisk->toBeNull()
        ->tempAssetUploadDisk->toBeNull();
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
