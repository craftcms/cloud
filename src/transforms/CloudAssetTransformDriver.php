<?php

declare(strict_types=1);

namespace craft\cloud\transforms;

use craft\cloud\signing\UrlSigner;
use CraftCms\Cms\Asset\AssetsHelper;
use CraftCms\Cms\Asset\AssetTransformers;
use CraftCms\Cms\Asset\Contracts\AssetTransformDriver;
use CraftCms\Cms\Asset\Data\AssetTransformDriverDefinition;
use CraftCms\Cms\Asset\Data\AssetTransformRequest;
use CraftCms\Cms\Asset\Data\AssetTransformResult;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Image\Data\ImageTransform;
use CraftCms\Cms\Shared\Exceptions\NotSupportedException;
use CraftCms\Cms\Support\File;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Arr;
use League\Uri\Components\Query;
use League\Uri\Modifier;

/**
 * Transforms images and PDFs in Craft Cloud’s bucket with signed Cloudflare Image Resizing URLs on the asset CDN.
 * Assets anywhere else, such as on `craft-cloud` disks in local development, go to the `craft` driver.
 *
 * Projects choose it per disk:
 *
 * ```php
 * 'images' => [
 *     'driver' => 'craft-cloud',
 *     'asset_transformer' => 'craft-cloud',
 * ],
 * ```
 */
class CloudAssetTransformDriver implements AssetTransformDriver
{
    public const string DRIVER = 'craft-cloud';

    /** Source extensions the Cloud gateway transforms with Cloudflare Images. */
    public const array SUPPORTED_IMAGE_EXTENSIONS = [
        'avif',
        'gif',
        'heic',
        'heif',
        'jpeg',
        'jpg',
        'png',
        'svg',
        'webp',
    ];

    public function __construct(
        private readonly UrlSigner $urlSigner,
        private readonly AssetTransformers $assetTransformers,
    ) {}

    public function definition(): AssetTransformDriverDefinition
    {
        return new AssetTransformDriverDefinition(parameterRules: CloudflareOptions::parameterRules());
    }

    public function transform(AssetTransformRequest $request): AssetTransformResult
    {
        $asset = $request->asset;

        if (!$this->isOnCloudStorage($asset)) {
            return $this->assetTransformers->transform(
                $asset,
                Arr::only($request->parameters, ImageTransform::CORE_PARAMETERS),
                transformer: 'craft',
            );
        }

        $extension = strtolower($asset->getExtension());
        $this->ensureSupported($extension);

        $options = CloudflareOptions::fromParameters(
            $request->parameters,
            $asset->getHasFocalPoint() ? $asset->getFocalPoint() : null,
        );

        if ($extension === 'pdf') {
            $options['format'] ??= 'auto';
        }

        $url = Modifier::wrap(AssetsHelper::generateUrl($asset))
            ->mergeQuery(Query::fromVariable($options))
            ->removeQueryParameters('v')
            ->removeEmptyQueryPairs()
            ->toString();

        // `auto` is negotiated by the gateway; PDFs rasterize to PNG when it can’t negotiate.
        $format = match ($options['format'] ?? 'auto') {
            'auto' => $extension === 'pdf' ? 'png' : $extension,
            'baseline-jpeg' => 'jpeg',
            default => $options['format'],
        };

        return new AssetTransformResult(
            url: $this->urlSigner->sign($url),
            mimeType: File::getMimeTypeByExtension("transform.{$format}") ?? 'application/octet-stream',
        );
    }

    /**
     * Whether the asset is in Cloud’s bucket, which the asset CDN serves.
     *
     * This checks the volume’s own disk, so scoped disks count, and so do disks that aren’t defined with the
     * `craft-cloud` driver. Temporary uploads are in the bucket, but outside the CDN’s prefix.
     */
    protected function isOnCloudStorage(Asset $asset): bool
    {
        $volume = $asset->getVolume();
        $bucket = $_SERVER['CRAFT_CLOUD_PROJECT_ID'] ?? null;

        if ($bucket === null || $volume->isTemporary() || $volume->getResolvedFsTarget() === null) {
            return false;
        }

        $disk = $volume->sourceDisk();

        return $disk instanceof AwsS3V3Adapter && ($disk->getConfig()['bucket'] ?? null) === $bucket;
    }

    private function ensureSupported(string $extension): void
    {
        if (!in_array($extension, [...self::SUPPORTED_IMAGE_EXTENSIONS, 'pdf'], strict: true)) {
            throw new NotSupportedException("Craft Cloud can’t transform .{$extension} files.");
        }

        $disabled = ['gif' => !Cms::config()->transformGifs, 'svg' => !Cms::config()->transformSvgs];

        if ($disabled[$extension] ?? false) {
            throw new NotSupportedException("Craft is configured not to transform .{$extension} files.");
        }
    }
}
