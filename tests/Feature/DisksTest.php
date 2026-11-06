<?php

declare(strict_types=1);

use craft\cloud\Console\DisksCommand;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use Illuminate\Container\Container;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

beforeEach(function (): void {
    $this->savedServer = array_intersect_key($_SERVER, array_flip(CLOUD_SERVER_KEYS));

    foreach (CLOUD_SERVER_KEYS as $key) {
        unset($_SERVER[$key]);
    }

    // A real application, for `public_path()`
    $this->app = new Application(sys_get_temp_dir() . '/craft-cloud-tests');
    $this->app->instance('config', $this->config);
    $this->app->singleton('filesystem', static fn(Application $app) => new FilesystemManager($app));
    $this->app->singleton(GeneralConfig::class, GeneralConfig::create(...));
    $this->config->set('filesystems.disks', [
        'images' => ['driver' => 'craft-cloud', 'subpath' => 'images'],
    ]);

    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->app);
});

afterEach(function (): void {
    foreach (CLOUD_SERVER_KEYS as $key) {
        unset($_SERVER[$key]);
    }

    $_SERVER = [...$_SERVER, ...$this->savedServer];
    Container::setInstance(null);
});

const ON_CLOUD = [
    'CRAFT_CLOUD' => '1',
    'CRAFT_CLOUD_PROJECT_ID' => 'project-bucket',
    'CRAFT_CLOUD_ENVIRONMENT_ID' => 'environment-id',
    'CRAFT_CLOUD_REGION' => 'us-west-2',
];

function on_cloud(): void
{
    $_SERVER = [...$_SERVER, ...ON_CLOUD];
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

    expect($disk)
        ->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($disk->getConfig())
        ->toMatchArray([
            'bucket' => 'project-bucket',
            'region' => 'us-west-2',
            'root' => 'environment-id/assets/images',
            'visibility' => 'private',
        ])
        ->and($disk->path('photo.jpg'))
        ->toBe('environment-id/assets/images/photo.jpg')
        ->and($disk->url('photo.jpg'))
        ->toBe('https://cdn.craft.cloud/environment-id/assets/images/photo.jpg')
        ->and([
            $sdkCredentials->getAccessKeyId(),
            $sdkCredentials->getSecretKey(),
            $sdkCredentials->getSecurityToken(),
        ])
        ->toBe($credentials);
});

it('applies volume subpaths from scoped disks inside the disk’s own subpath', function (
    array $server,
    string $url,
): void {
    $_SERVER = [...$_SERVER, ...$server];

    $this->registerProvider();

    $disk = Storage::build(['driver' => 'scoped', 'disk' => 'images', 'prefix' => 'volume']);

    expect($disk->url('photo.jpg'))->toBe($url);
})->with([
    'on Cloud' => [ON_CLOUD, 'https://cdn.craft.cloud/environment-id/assets/images/volume/photo.jpg'],
    'off Cloud' => [[], '/uploads/images/volume/photo.jpg'],
]);

it('uses a storage endpoint and CDN base URL when there are ones', function (): void {
    on_cloud();
    $_SERVER['CRAFT_CLOUD_STORAGE_ENDPOINT'] = 'http://minio:9000';
    $_SERVER['CRAFT_CLOUD_CDN_BASE_URL'] = 'https://cdn.example.test/';
    $this->config->set('filesystems.disks.custom-cdn', [
        'driver' => 'craft-cloud',
        'url' => 'https://assets.example.test',
        'subpath' => '$IMAGES_SUBPATH',
    ]);
    $_SERVER['IMAGES_SUBPATH'] = 'from-env';
    $this->registerProvider();

    expect(Storage::disk('images')->getConfig())
        ->toMatchArray(['endpoint' => 'http://minio:9000', 'use_path_style_endpoint' => true])
        ->not
        ->toHaveKeys(['key', 'secret', 'token'])
        ->and(Storage::disk('images')->url('photo.jpg'))
        ->toBe('https://cdn.example.test/environment-id/assets/images/photo.jpg')
        ->and(Storage::disk('custom-cdn')->url('photo.jpg'))
        ->toBe('https://assets.example.test/environment-id/assets/from-env/photo.jpg');

    unset($_SERVER['IMAGES_SUBPATH']);
});

it('falls back to local disks in the webroot off Cloud', function (): void {
    $this->registerProvider();

    $disk = Storage::disk('images');

    expect($disk)
        ->toBeInstanceOf(LocalFilesystemAdapter::class)
        ->and($disk->path('photo.jpg'))
        ->toBe($this->app->publicPath('uploads/images/photo.jpg'))
        ->and($disk->url('photo.jpg'))
        ->toBe('/uploads/images/photo.jpg')
        ->and(Storage::disk('craft-cloud')->url('photo.jpg'))
        ->toBe('/uploads/photo.jpg')
        ->and($this->config->get('filesystems.disks.craft-cloud-uploads'))
        ->toBeNull()
        ->and($this->config->get('filesystems.disks.craft-cloud-temp'))
        ->toBeNull()
        ->and($this->app->make(GeneralConfig::class))
        ->uploadSessionDisk->toBeNull()
        ->tempAssetUploadDisk->toBeNull();
});

it('stages uploads and temporary assets in the bucket on Cloud', function (): void {
    on_cloud();
    $this->registerProvider();

    $config = $this->app->make(GeneralConfig::class);

    expect(Storage::disk($config->getUploadSessionDisk())->path('session/photo.jpg'))
        ->toBe('environment-id/storage/tmp/uploads/session/photo.jpg')
        ->and(Storage::disk($config->getTempAssetUploadDisk())->path('user_1/photo.jpg'))
        ->toBe('environment-id/storage/tmp/user_1/photo.jpg');
});

it('leaves disks and settings the project has chosen alone', function (): void {
    on_cloud();
    $project = ['driver' => 'local', 'root' => '/srv/assets'];
    $this->config->set('filesystems.disks.craft-cloud', $project);
    $this->app->singleton(GeneralConfig::class, static fn() => GeneralConfig::create()
        ->uploadSessionDisk('project-uploads')
        ->tempAssetUploadDisk('project-temp'));
    $this->registerProvider();

    expect($this->config->get('filesystems.disks.craft-cloud'))
        ->toBe($project)
        ->and($this->app->make(GeneralConfig::class))
        ->getUploadSessionDisk()
        ->toBe('project-uploads')
        ->getTempAssetUploadDisk()
        ->toBe('project-temp');
});

it('needs a bucket and environment on Cloud', function (): void {
    on_cloud();
    unset($_SERVER['CRAFT_CLOUD_PROJECT_ID']);
    $this->registerProvider();

    Storage::disk('images');
})->throws(InvalidArgumentException::class, 'CRAFT_CLOUD_PROJECT_ID');

it('prints disks for 3.x Craft Cloud filesystems, with the same handles', function (): void {
    $projectConfig = new class extends ProjectConfig {
        public function __construct() {}

        #[\Override]
        public function get(?string $path = null, bool $getFromExternalConfig = false): mixed
        {
            return [
                'images' => [
                    'type' => 'craft\cloud\fs\AssetsFs',
                    'name' => 'Images',
                    'settings' => ['subpath' => 'images', 'expires' => '1 years', 'baseUrl' => 'https://example.test'],
                ],
                'documents' => [
                    'type' => 'craft\cloud\fs\AssetsFs',
                    'settings' => ['subpath' => null, 'baseUrl' => ''],
                ],
                'local' => [
                    'type' => 'craft\fs\Local',
                    'settings' => ['path' => '@webroot/files'],
                ],
            ];
        }
    };
    $this->app->instance(ProjectConfig::class, $projectConfig);
    $command = new DisksCommand();
    $command->setLaravel($this->app);
    $output = new BufferedOutput();

    expect($command->run(new ArrayInput([]), $output))->toBe(0);

    // The printed entries can be pasted into a `disks` array as they are
    preg_match('/^\'.*^\],$/ms', $output->fetch(), $printed);
    $file = tempnam(sys_get_temp_dir(), prefix: 'disks');
    file_put_contents($file, '<?php return [' . $printed[0] . '];');
    $disks = require $file;
    unlink($file);

    expect($disks)->toBe([
        'images' => ['driver' => 'craft-cloud', 'subpath' => 'images', 'expires' => '1 years'],
        'documents' => ['driver' => 'craft-cloud'],
    ]);
});
