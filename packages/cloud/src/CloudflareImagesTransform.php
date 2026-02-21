<?php

namespace craft\cloud;

use Craft;
use craft\elements\Asset;
use craft\models\ImageTransform;
use Illuminate\Support\Collection;

class CloudflareImagesTransform
{
    /**
     * @see https://developers.cloudflare.com/images/transform-images/transform-via-workers/#fetch-options
     * @see https://github.com/cloudflare/workerd/blob/main/types/defines/cf.d.ts
     *
     * @param array{color: string, width: int}|array{color: string, top: int, right: int, bottom: int, left: int}|null $border
     * @param array{url: string, opacity?: float, repeat?: true|'x'|'y', top?: int, left?: int, bottom?: int, right?: int, width?: int, height?: int, fit?: 'scale-down'|'contain'|'cover'|'crop'|'pad'|'squeeze', gravity?: 'face'|'left'|'right'|'top'|'bottom'|'center'|'auto'|'entropy'|array{x?: float, y?: float, mode?: 'remainder'|'box-center'}, background?: string, rotate?: 0|90|180|270|360, segment?: 'foreground'}[]|null $draw
     * @param 'face'|'left'|'right'|'top'|'bottom'|'center'|'auto'|'entropy'|array{x?: float, y?: float, mode?: 'remainder'|'box-center'}|null $gravity
     * @param int|'low'|'medium-low'|'medium-high'|'high'|null $quality
     * @param 'border'|array{top?: int, bottom?: int, left?: int, right?: int, width?: int, height?: int, border?: bool|array{color?: string, tolerance?: int, keep?: int}}|null $trim
     */
    public function __construct(
        public ?bool $anim = null,
        public ?string $background = null,
        public ?int $blur = null,
        public ?array $border = null,
        public ?float $brightness = null,
        public ?string $compression = null,
        public ?float $contrast = null,
        public ?float $dpr = null,
        public ?array $draw = null,
        public ?string $fit = null,
        public ?string $flip = null,
        public ?string $format = null,
        public ?float $gamma = null,
        public null|string|array $gravity = null,
        public ?int $height = null,
        public ?string $metadata = null,
        public ?string $onerror = null,
        public ?string $originAuth = null,
        public null|int|string $quality = null,
        public ?int $rotate = null,
        public ?float $saturation = null,
        public ?string $segment = null,
        public ?float $sharpen = null,
        public null|string|array $trim = null,
        public null|int|string $width = null,
        public ?float $zoom = null,
    ) {
    }

    public static function fromImageTransform(ImageTransform $imageTransform): self
    {
        $fields = Collection::make($imageTransform->toArray())
            ->merge([
                'background' => self::resolveBackground($imageTransform),
                'fit' => self::resolveFit($imageTransform),
                'format' => self::resolveFormat($imageTransform),
                'gravity' => self::resolveGravity($imageTransform),
            ])
            ->filter(fn($value) => $value !== null)
            ->filter(fn($value, $key) => property_exists(self::class, $key));

        return new self(...$fields->all());
    }

    private static function resolveFormat(ImageTransform $imageTransform): ?string
    {
        if ($imageTransform->format === 'jpg' && $imageTransform->interlace === 'none') {
            return 'baseline-jpeg';
        }

        return match ($imageTransform->format) {
            'jpg' => 'jpeg',
            default => $imageTransform->format,
        };
    }

    /**
     * @see https://developers.cloudflare.com/images/transform-images/transform-via-url/#fit
     */
    private static function resolveFit(ImageTransform $imageTransform): string
    {
        // Cloudflare doesn't have an exact match to `stretch`.
        // `cover` is close, but will crop instead of stretching.
        return match ($imageTransform->mode) {
            'fit' => $imageTransform->upscale ? 'contain' : 'scale-down',
            'stretch' => 'cover',
            'letterbox' => 'pad',
            default => $imageTransform->upscale ? 'cover' : 'crop',
        };
    }

    private static function resolveBackground(ImageTransform $imageTransform): ?string
    {
        return $imageTransform->mode === 'letterbox'
            ? $imageTransform->fill ?? '#FFFFFF'
            : null;
    }

    /**
     * @return array{x: float, y: float}|null
     */
    private static function resolveGravity(ImageTransform $imageTransform): ?array
    {
        if ($imageTransform->position === 'center-center') {
            return null;
        }

        // TODO: maybe just do this in Craft
        $parts = explode('-', $imageTransform->position);

        try {
            $x = match ($parts[1] ?? null) {
                'left' => 0,
                'center' => 0.5,
                'right' => 1,
            };
            $y = match ($parts[0] ?? null) {
                'top' => 0,
                'center' => 0.5,
                'bottom' => 1,
            };
        } catch (\UnhandledMatchError $e) {
            Craft::warning("Invalid position value: `{$imageTransform->position}`", __METHOD__);
            return null;
        }

        return [
            'x' => $x,
            'y' => $y,
        ];
    }

    public static function fromAsset(Asset $asset, ?ImageTransform $imageTransform = null): self
    {
        $cfTransform = $imageTransform ? self::fromImageTransform($imageTransform) : new self();

        if ($asset->getHasFocalPoint()) {
            $cfTransform->gravity = $asset->getFocalPoint();
        }

        return $cfTransform;
    }
}
