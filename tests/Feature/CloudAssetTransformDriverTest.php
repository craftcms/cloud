<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use craft\cloud\signing\UrlSigner;
use craft\cloud\transforms\CloudAssetTransformDriver;
use CraftCms\Cms\Asset\AssetTransformers;
use CraftCms\Cms\Asset\Data\AssetTransformer;
use CraftCms\Cms\Asset\Data\AssetTransformRequest;
use CraftCms\Cms\Asset\Data\AssetTransformResult;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Shared\Exceptions\NotSupportedException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Uri\Components\Query;

const CDN_URL = 'https://cdn.craft.cloud/test-environment/assets';

function s3Disk(): AwsS3V3Adapter
{
    $adapter = new LocalFilesystemAdapter(sys_get_temp_dir());

    return new AwsS3V3Adapter(new Filesystem($adapter), $adapter, ['url' => CDN_URL], new S3Client([
        'region' => 'us-east-1',
        'credentials' => false,
    ]));
}

function transformAsset(string $filename, string $disk = 'cloud', ?array $focalPoint = null): Asset
{
    $asset = new class(['siteId' => 1, 'filename' => $filename, 'folderPath' => 'tests/']) extends Asset {
        public Volume $volume;

        public function getVolume(): Volume
        {
            return $this->volume;
        }
    };
    $asset->volume = new Volume(['fs' => $disk, 'hasUrls' => true]);

    if ($focalPoint !== null) {
        $asset->kind = 'image';
        $asset->setFocalPoint($focalPoint);
    }

    return $asset;
}

beforeEach(function (): void {
    $this->config->set('filesystems.disks', [
        'cloud' => ['driver' => 'craft-cloud', 'onCloud' => true],
        'cloud-local' => ['driver' => 'craft-cloud', 'onCloud' => false],
        'customer-s3' => ['driver' => 's3'],
    ]);

    $filesystem = new FilesystemManager($this->app);
    $filesystem->extend('craft-cloud', fn($app, array $config) => (
        $config['onCloud'] ? s3Disk() : $filesystem->createLocalDriver(['root' => sys_get_temp_dir()])
    ));
    $filesystem->extend('s3', fn() => s3Disk());

    $this->app->instance('filesystem', $filesystem);
    $this->app->instance(GeneralConfig::class, GeneralConfig::create());

    $this->assetTransformers = new class extends AssetTransformers {
        /** @var list<array{mixed, ?string}> */
        public array $calls = [];

        public function __construct() {}

        public function transform(Asset $asset, mixed $definition, ?string $transformer = null): AssetTransformResult
        {
            $this->calls[] = [$definition, $transformer];

            return new AssetTransformResult(url: 'https://example.test/craft-transform.jpg', mimeType: 'image/jpeg');
        }
    };
    $this->urlSigner = new UrlSigner('test-signing-key');
    $this->driver = new CloudAssetTransformDriver($this->urlSigner, $this->assetTransformers);
    $this->transform = fn(
        Asset $asset,
        array $parameters,
    ): AssetTransformResult => $this->driver->transform(new AssetTransformRequest(
        asset: $asset,
        transformer: new AssetTransformer(['handle' => 'craftCloud', 'driver' => 'craft-cloud']),
        parameters: $parameters,
    ));
});

it('returns signed Cloudflare transform URLs for images on Cloud disks', function (): void {
    $result = ($this->transform)(transformAsset('photo.jpg', focalPoint: ['x' => 0.57, 'y' => 0.7707]), [
        'format' => 'webp',
        'height' => 750,
        'mode' => 'crop',
        'position' => 'top-center',
        'width' => 1200,
    ]);

    expect($result->url)->toStartWith(CDN_URL . '/tests/photo.jpg?');
    expect($result->mimeType)->toBe('image/webp');
    expect(Query::fromUri($result->url)->parameters())->toMatchArray([
        'fit' => 'cover',
        'format' => 'webp',
        'gravity' => ['x' => '0.57', 'y' => '0.7707'],
        'height' => '750',
        'width' => '1200',
    ]);
    expect($this->urlSigner->verify($result->url))->toBeTrue();
    expect(new UrlSigner('other-key')->verify($result->url))->toBeFalse();
});

it('removes the asset revision parameter before signing', function (): void {
    Cms::config()->revAssetUrls = true;
    $asset = transformAsset('test image.jpg');
    $asset->dateModified = new DateTime('@1700000000');

    $result = ($this->transform)($asset, ['height' => 100, 'width' => 100]);
    $parameters = Query::fromUri($result->url)->parameters();

    expect($result->url)->toStartWith(CDN_URL . '/tests/test%20image.jpg?');
    expect($result->url)->not->toContain('?&');
    expect($parameters)->not->toHaveKey('v');
    expect($parameters['width'])->toBe('100');
    expect($this->urlSigner->verify($result->url))->toBeTrue();
});

it('rasterizes PDFs with a signed transform', function (array $parameters, array $expected, string $mimeType): void {
    $result = ($this->transform)(transformAsset('document.pdf'), $parameters);

    expect($result->url)->toStartWith(CDN_URL . '/tests/document.pdf?');
    expect(Query::fromUri($result->url)->parameters())->toMatchArray($expected);
    expect($result->mimeType)->toBe($mimeType);
    expect($this->urlSigner->verify($result->url))->toBeTrue();
})->with([
    'thumbnail' => [
        ['height' => 320, 'mode' => 'crop', 'width' => 320],
        ['fit' => 'cover', 'format' => 'auto', 'height' => '320', 'width' => '320'],
        'image/png',
    ],
    'page and format' => [
        ['format' => 'webp', 'height' => 480, 'mode' => 'fit', 'page' => 2, 'upscale' => false, 'width' => 640],
        ['fit' => 'scale-down', 'format' => 'webp', 'height' => '480', 'page' => '2', 'width' => '640'],
        'image/webp',
    ],
]);

it('falls back to the Craft transformer for assets outside Cloud storage', function (
    string $disk,
    string $volume,
): void {
    $asset = transformAsset('photo.jpg', $disk);

    if ($volume === 'temporary') {
        $asset->getVolume()->markAsTemporary();
    }

    $result = ($this->transform)($asset, [
        'blur' => 5,
        'mode' => 'fit',
        'width' => 100,
    ]);

    expect($result->url)->toBe('https://example.test/craft-transform.jpg');
    expect($this->assetTransformers->calls)->toBe([[['mode' => 'fit', 'width' => 100], 'craft']]);
})->with([
    'Cloud disk off Cloud' => ['cloud-local', 'regular'],
    'non-Cloud S3 disk' => ['customer-s3', 'regular'],
    'temporary uploads on a Cloud disk' => ['cloud', 'temporary'],
]);

it('does not transform unsupported files on Cloud disks', function (string $filename, array $config): void {
    foreach ($config as $setting => $value) {
        Cms::config()->$setting = $value;
    }

    ($this->transform)(transformAsset($filename), ['width' => 100]);
})->with([
    'unsupported extension' => ['archive.tiff', []],
    'GIFs when disabled' => ['animation.gif', ['transformGifs' => false]],
    'SVGs when disabled' => ['logo.svg', ['transformSvgs' => false]],
])->throws(NotSupportedException::class);

it('declares the Cloudflare parameters', function (): void {
    $definition = $this->driver->definition();

    expect($definition->name)->toBe('Craft Cloud');
    expect($definition->parameterRules)->toHaveKeys(['blur', 'fit', 'gravity', 'page', 'quality', 'zoom']);
});
