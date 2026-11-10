<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use craft\cloud\transforms\AssetCdn;
use craft\cloud\transforms\CloudAssetTransformDriver;
use CraftCms\Cms\Asset\AssetsHelper;
use CraftCms\Cms\Asset\AssetTransformers;
use CraftCms\Cms\Asset\Contracts\AssetTransformDriver;
use CraftCms\Cms\Asset\Data\AssetTransformDriverDefinition;
use CraftCms\Cms\Asset\Data\AssetTransformer;
use CraftCms\Cms\Asset\Data\AssetTransformRequest;
use CraftCms\Cms\Asset\Data\AssetTransformResult;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Shared\Exceptions\NotSupportedException;
use GuzzleHttp\Psr7\HttpFactory;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Exception\VerificationException;
use HttpMessageSignatures\Url\UrlSigningConfig;
use HttpMessageSignatures\Url\UrlVerifier;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Uri\Components\Query;

const CDN_URL = 'https://cdn.craft.cloud/test-environment/assets';

function s3Disk(array $config): AwsS3V3Adapter
{
    $adapter = new LocalFilesystemAdapter(sys_get_temp_dir());

    return new AwsS3V3Adapter(new Filesystem($adapter), $adapter, $config, new S3Client([
        'region' => 'us-east-1',
        'credentials' => false,
    ]));
}

function transformAsset(string $filename, string $disk = 'cloud', ?array $focalPoint = null, ?array $size = null): Asset
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

    if ($size !== null) {
        $asset->kind = 'image';
        [$width, $height] = $size;
        $asset->setWidth($width);
        $asset->setHeight($height);
    }

    return $asset;
}

/**
 * Verifies a URL like the Cloud gateway does: without `v`, over `@path` and `@query`, with the signature in `s`.
 */
function gatewayVerifies(string $url, string $key = 'test-signing-key'): bool
{
    [$base, $query] = explode('?', $url, limit: 2) + [1 => ''];
    $query = implode('&', array_filter(
        explode('&', $query),
        static fn(string $pair): bool => !str_starts_with($pair, 'v='),
    ));
    $verifier = new UrlVerifier(
        new HmacSha256($key),
        new HttpFactory(),
        new UrlSigningConfig(components: ['@path', '@query'], signatureParam: 's'),
    );

    try {
        return $verifier->verify("{$base}?{$query}");
    } catch (VerificationException) {
        return false;
    }
}

beforeEach(function (): void {
    $this->config->set('filesystems.disks', [
        'cloud' => ['driver' => 'craft-cloud'],
        'cloud-images' => ['driver' => 'craft-cloud', 'prefix' => 'images'],
        'cloud-blog' => ['driver' => 'scoped', 'disk' => 'cloud', 'prefix' => 'blog'],
        'customer-s3' => ['driver' => 's3', 'bucket' => 'customer-bucket', 'url' => 'https://customer.example.test'],
        'local' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/uploads'],
    ]);

    // Like the `craft-cloud` disk driver: an S3 disk on the project's bucket, with CDN URLs
    $filesystem = new FilesystemManager($this->app);
    $filesystem->extend('craft-cloud', fn($app, array $config) => s3Disk([
        ...$config,
        'bucket' => 'cloud-project',
        'root' => 'test-environment/assets',
        'url' => 'https://cdn.craft.cloud',
    ]));
    $filesystem->extend('s3', fn($app, array $config) => s3Disk($config));

    $this->app->instance('filesystem', $filesystem);
    $this->app->instance(GeneralConfig::class, GeneralConfig::create());

    $this->craftDriver = new class implements AssetTransformDriver {
        /** @var list<AssetTransformRequest> */
        public array $requests = [];

        public function definition(): AssetTransformDriverDefinition
        {
            return new AssetTransformDriverDefinition();
        }

        public function transform(AssetTransformRequest $request): AssetTransformResult
        {
            $this->requests[] = $request;

            return new AssetTransformResult(url: 'https://example.test/craft-transform.jpg', mimeType: 'image/jpeg');
        }
    };
    $this->assetTransformers = new AssetTransformers($this->app);
    $craftDriver = $this->craftDriver;
    $this->assetTransformers->extend('craft', fn() => $craftDriver);
    $this->driver = new CloudAssetTransformDriver($this->assetTransformers, new AssetCdn('test-signing-key'));
    $this->transform = fn(
        Asset $asset,
        array $parameters,
    ): AssetTransformResult => $this->driver->transform(new AssetTransformRequest(
        asset: $asset,
        transformer: new AssetTransformer('craft-cloud'),
        parameters: $parameters,
    ));
});

it('returns signed Cloudflare image URLs for images on Cloud disks', function (): void {
    $asset = transformAsset('photo.jpg', focalPoint: ['x' => 0.57, 'y' => 0.7707], size: [4000, 3000]);

    $result = ($this->transform)($asset, [
        'format' => 'webp',
        'height' => 750,
        'mode' => 'crop',
        'position' => 'top-center',
        'width' => 1200,
    ]);

    expect($result->url)->toStartWith(CDN_URL . '/tests/photo.jpg?');
    expect(Query::fromUri($result->url)->parameters())->toEqual([
        'width' => '1200',
        'height' => '750',
        'format' => 'webp',
        'fit' => 'cover',
        'gravity' => ['x' => '0.57', 'y' => '0.7707'],
        's' => Query::fromUri($result->url)->get('s'),
    ]);
    expect([$result->mimeType, $result->width, $result->height])->toBe(['image/webp', 1200, 750]);
    expect(gatewayVerifies($result->url))->toBeTrue();
    expect(gatewayVerifies($result->url, key: 'other-key'))->toBeFalse();
    expect(gatewayVerifies(str_replace(
        search: 'width=1200',
        replace: 'width=2400',
        subject: $result->url,
    )))->toBeFalse();
});

it('reports the output size like the craft driver', function (array $parameters, array $expected): void {
    $result = ($this->transform)(transformAsset('photo.jpg', size: [800, 600]), $parameters);

    expect([$result->width, $result->height])->toBe($expected);
})->with([
    'crop' => [['height' => 100, 'mode' => 'crop', 'width' => 200], [200, 100]],
    'fit within the source' => [['mode' => 'fit', 'width' => 400], [400, 300]],
    'fit without upscaling' => [['mode' => 'fit', 'upscale' => false, 'width' => 1600], [800, 600]],
]);

it('follows upscaleImages when a transform doesn’t set upscale', function (): void {
    Cms::config()->upscaleImages = false;

    $result = ($this->transform)(transformAsset('photo.jpg', size: [800, 600]), ['mode' => 'fit', 'width' => 1600]);

    expect(Query::fromUri($result->url)->get('fit'))->toBe('scale-down');
    expect([$result->width, $result->height])->toBe([800, 600]);
});

it('transforms assets in subfolders of Cloud disks', function (string $disk, string $path): void {
    $result = ($this->transform)(transformAsset('photo.jpg', $disk), ['width' => 100]);

    expect($result->url)->toStartWith(CDN_URL . "/{$path}/tests/photo.jpg?");
    expect(gatewayVerifies($result->url))->toBeTrue();
})->with([
    'prefix option' => ['cloud-images', 'images'],
    'scoped disk' => ['cloud-blog', 'blog'],
]);

it('trusts the project’s choice of driver on any S3 disk', function (): void {
    $result = ($this->transform)(transformAsset('photo.jpg', disk: 'customer-s3'), ['width' => 100]);

    expect($result->url)->toStartWith('https://customer.example.test/tests/photo.jpg?');
    expect(gatewayVerifies($result->url))->toBeTrue();
});

it('adds the asset revision after signing', function (): void {
    Cms::config()->revAssetUrls = true;
    $asset = transformAsset('test image.jpg');
    $asset->dateModified = new DateTime('@1700000000');

    $result = ($this->transform)($asset, ['height' => 100, 'width' => 100]);

    expect($result->url)->toStartWith(CDN_URL . '/tests/test%20image.jpg?');
    expect($result->url)->toMatch('/&s=[^&]+&v=1700000000$/');
    expect(gatewayVerifies($result->url))->toBeTrue();
});

it('stays valid when core adds a revision to a thumbnail URL', function (): void {
    $asset = transformAsset('photo.jpg', focalPoint: ['x' => 0.25, 'y' => 0.5], size: [800, 600]);
    $asset->dateModified = new DateTime('@1700000000');

    $url = AssetsHelper::revUrl(
        ($this->transform)($asset, ['height' => 64, 'mode' => 'crop', 'width' => 64])->url,
        $asset,
        fsOnly: true,
    );

    expect($url)->toContain('v=1700000000');
    expect(gatewayVerifies($url))->toBeTrue();
});

it('rasterizes PDFs', function (array $parameters, array $expected, string $mimeType): void {
    $result = ($this->transform)(transformAsset('document.pdf'), $parameters);

    expect($result->url)->toStartWith(CDN_URL . '/tests/document.pdf?');
    expect(Query::fromUri($result->url)->parameters())->toMatchArray($expected);
    expect([$result->mimeType, $result->width])->toBe([$mimeType, null]);
    expect(gatewayVerifies($result->url))->toBeTrue();
})->with([
    'negotiated format' => [
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

it('hands assets on other disks to the craft driver', function (): void {
    $result = ($this->transform)(transformAsset('photo.jpg', disk: 'local'), [
        'blur' => 5,
        'mode' => 'fit',
        'width' => 100,
    ]);

    expect($result->url)->toBe('https://example.test/craft-transform.jpg');
    expect($this->craftDriver->requests)->toHaveCount(1);
    expect($this->craftDriver->requests[0]->transformer)->toEqual(new AssetTransformer('craft'));
    expect($this->craftDriver->requests[0]->parameters)->toBe(['mode' => 'fit', 'width' => 100]);
});

it('does not transform unsupported files', function (string $filename, array $config): void {
    foreach ($config as $setting => $value) {
        Cms::config()->$setting = $value;
    }

    ($this->transform)(transformAsset($filename), ['width' => 100]);
})->with([
    'unsupported extension' => ['archive.tiff', []],
    'GIFs when disabled' => ['animation.gif', ['transformGifs' => false]],
    'SVGs when disabled' => ['logo.svg', ['transformSvgs' => false]],
])->throws(NotSupportedException::class);

it('declares only the Cloudflare options core has no parameter for', function (): void {
    $rules = $this->driver->definition()->parameterRules;

    expect($rules)->toHaveKeys(['blur', 'gravity', 'page', 'zoom']);
    expect($rules)->not->toHaveKeys(['fit', 'format', 'quality', 'width']);
});
