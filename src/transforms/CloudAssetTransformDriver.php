<?php

declare(strict_types=1);

namespace craft\cloud\transforms;

use CraftCms\Cms\Asset\AssetTransformers;
use CraftCms\Cms\Asset\Contracts\AssetTransformDriver;
use CraftCms\Cms\Asset\Data\AssetTransformDriverDefinition;
use CraftCms\Cms\Asset\Data\AssetTransformer;
use CraftCms\Cms\Asset\Data\AssetTransformRequest;
use CraftCms\Cms\Asset\Data\AssetTransformResult;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Image\Data\ImageTransform;
use CraftCms\Cms\Image\ImageHelper;
use CraftCms\Cms\Shared\Exceptions\NotSupportedException;
use CraftCms\Cms\Support\File;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Arr;

/**
 * Transforms images and PDFs with signed Cloudflare image URLs on Craft Cloud’s asset CDN.
 *
 * Projects choose it per disk:
 *
 * ```php
 * 'images' => ['driver' => 'craft-cloud', 'prefix' => 'images', 'asset_transformer' => 'craft-cloud'],
 * ```
 *
 * It transforms every asset on the disks that choose it. Assets on disks that aren’t S3, which the CDN can’t serve,
 * go to the `craft` driver.
 */
class CloudAssetTransformDriver implements AssetTransformDriver
{
    public const string DRIVER = 'craft-cloud';

    /** Source extensions the gateway transforms. */
    private const array EXTENSIONS = ['avif', 'gif', 'heic', 'heif', 'jpeg', 'jpg', 'pdf', 'png', 'svg', 'webp'];

    public function __construct(
        private readonly AssetTransformers $transformers,
        private readonly AssetCdn $cdn,
    ) {}

    public function definition(): AssetTransformDriverDefinition
    {
        return new AssetTransformDriverDefinition(parameterRules: CloudflareOptions::rules());
    }

    public function transform(AssetTransformRequest $request): AssetTransformResult
    {
        $asset = $request->asset;
        $parameters = $request->parameters + ['upscale' => Cms::config()->upscaleImages];

        // Core can’t hand a transform to another driver, so call the `craft` driver directly.
        if (!$asset->getVolume()->sourceDisk() instanceof AwsS3V3Adapter) {
            return $this->transformers
                ->driver('craft')
                ->transform(new AssetTransformRequest(
                    asset: $asset,
                    transformer: new AssetTransformer('craft'),
                    parameters: Arr::only($request->parameters, ImageTransform::CORE_PARAMETERS),
                ));
        }

        $extension = $this->supportedExtension($asset);
        $options = CloudflareOptions::fromParameters(
            $parameters,
            $asset->getHasFocalPoint() ? $asset->getFocalPoint() : null,
        );

        // The gateway only rasterizes PDFs when a format is requested. `auto` lets it pick AVIF or WebP.
        if ($extension === 'pdf') {
            $options['format'] ??= 'auto';
        }

        $format = $parameters['format'] ?? ($extension === 'pdf' ? 'png' : $extension);
        [$width, $height] = $this->outputDimensions($asset, $parameters);

        return new AssetTransformResult(
            url: $this->cdn->transformUrl($asset, $options),
            mimeType: File::getMimeTypeByExtension("transform.{$format}") ?? 'application/octet-stream',
            width: $width,
            height: $height,
        );
    }

    private function supportedExtension(Asset $asset): string
    {
        $extension = strtolower($asset->getExtension());
        $disabled = ['gif' => !Cms::config()->transformGifs, 'svg' => !Cms::config()->transformSvgs];

        if (!in_array($extension, self::EXTENSIONS, strict: true) || ($disabled[$extension] ?? false)) {
            throw new NotSupportedException("Craft Cloud doesn’t transform .{$extension} files.");
        }

        return $extension;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{?int, ?int}
     */
    private function outputDimensions(Asset $asset, array $parameters): array
    {
        $sourceWidth = $asset->getWidth();
        $sourceHeight = $asset->getHeight();

        if (!$sourceWidth || !$sourceHeight) {
            return [null, null];
        }

        return ImageHelper::targetDimensions(
            $sourceWidth,
            $sourceHeight,
            filter_var($parameters['width'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            filter_var($parameters['height'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            $parameters['mode'] ?? 'crop',
            filter_var($parameters['upscale'], FILTER_VALIDATE_BOOL),
        );
    }
}
