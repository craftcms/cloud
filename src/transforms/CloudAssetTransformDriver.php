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
use Illuminate\Support\Facades\Storage;
use League\Uri\Components\Query;
use League\Uri\Modifier;

/**
 * Transforms images and PDFs on Craft Cloud disks with signed Cloudflare Image Resizing URLs on the asset CDN.
 * Assets on other disks use Craft’s built-in transformer.
 */
class CloudAssetTransformDriver implements AssetTransformDriver
{
    public const string DRIVER = 'craft-cloud';

    /** Handle of the transformer profile Cloud registers for this driver. */
    public const string TRANSFORMER = 'craftCloud';

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
        return new AssetTransformDriverDefinition(
            name: 'Craft Cloud',
            parameterRules: CloudflareOptions::parameterRules(),
        );
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
     * Whether the asset is in Cloud’s bucket, under the prefix the asset CDN serves.
     *
     * `craft-cloud` disks resolve to S3 on Craft Cloud and to local disks elsewhere. Temporary uploads are in the
     * bucket, but outside the CDN’s prefix.
     */
    protected function isOnCloudStorage(Asset $asset): bool
    {
        $volume = $asset->getVolume();
        $disk = $volume->getResolvedFsTarget();

        return (
            $disk !== null
            && !$volume->isTemporary()
            && config("filesystems.disks.{$disk}.driver") === 'craft-cloud'
            && Storage::disk($disk) instanceof AwsS3V3Adapter
        );
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
