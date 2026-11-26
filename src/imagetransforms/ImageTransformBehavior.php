<?php

namespace craft\cloud\imagetransforms;

use Craft;
use craft\cloud\Module;
use craft\elements\Asset;
use craft\models\ImageTransform;
use Illuminate\Support\Collection;
use yii\base\Behavior;

/**
 * @see https://developers.cloudflare.com/images/transform-images/transform-via-workers/#fetch-options
 * @see https://github.com/cloudflare/workerd/blob/main/types/defines/cf.d.ts
 *
 * @property ImageTransform $owner
 */
class ImageTransformBehavior extends Behavior
{
    public ?bool $anim = null;
    public ?string $background = null;

    /**
     * @var int<1, 250>|null
     */
    public ?int $blur = null;

    /**
     * @var array{color: string, width: int}|array{color: string, top: int, right: int, bottom: int, left: int}|null
     */
    public ?array $border = null;

    /**
     * @var float|null
     */
    public ?float $brightness = null;

    /**
     * @var 'fast'|null
     */
    public ?string $compression = null;

    /**
     * @var float|null
     */
    public ?float $contrast = null;

    /**
     * @var float|null
     */
    public ?float $dpr = null;

    /**
     * @var array{url: string, opacity?: float, repeat?: true|'x'|'y', top?: int, left?: int, bottom?: int, right?: int, width?: int, height?: int, fit?: 'scale-down'|'contain'|'cover'|'crop'|'pad'|'squeeze', gravity?: 'face'|'left'|'right'|'top'|'bottom'|'center'|'auto'|'entropy'|array{x?: float, y?: float, mode?: 'remainder'|'box-center'}, background?: string, rotate?: 0|90|180|270|360, segment?: 'foreground'}[]|null Draw overlays
     */
    public ?array $draw = null;

    /**
     * @var 'scale-down'|'contain'|'cover'|'crop'|'aspect-crop'|'pad'|'squeeze'|null
     */
    public ?string $fit = null;

    /**
     * @var 'h'|'v'|'hv'|null
     */
    public ?string $flip = null;

    /**
     * @var float|null
     */
    public ?float $gamma = null;

    /**
     * @var 'auto'|'face'|'left'|'right'|'top'|'bottom'|array{x?: float, y?: float, mode?: 'remainder'|'box-center'}|null
     */
    public string|array|null $gravity = null;

    /**
     * @var 'keep'|'copyright'|'none'|null
     */
    public ?string $metadata = null;

    /**
     * @var int|null PDF page number.
     */
    public ?int $page = null;

    /**
     * @var int|null
     */
    public ?int $rotate = null;

    /**
     * @var float|null
     */
    public ?float $saturation = null;

    /**
     * @var 'foreground'|null
     */
    public ?string $segment = null;

    /**
     * @var float|null
     */
    public ?float $sharpen = null;

    /**
     * @var 'border'|array{top?: int, bottom?: int, left?: int, right?: int, width?: int, height?: int, border?: bool|array{color?: string, tolerance?: int, keep?: int}}|null
     */
    public null|string|array $trim = null;

    public ?float $zoom = null;

    /**
     * @param Asset|null $asset The asset being transformed. Without it, nothing that depends on the source is applied.
     */
    public function toOptions(?Asset $asset = null): array
    {
        $reflection = new \ReflectionClass($this);

        $options = Collection::make($reflection->getProperties(\ReflectionProperty::IS_PUBLIC))
            ->filter(fn($property) => $property->getDeclaringClass()->getName() === self::class)
            ->mapWithKeys(fn($property) => [$property->getName() => $property->getValue($this)])
            ->all();

        // Compute derived Cloudflare values from Craft's base transform settings,
        // without mutating the model (so the same instance can be safely reused).
        $options['format'] = $this->computeFormat($asset);
        $options['quality'] = $this->computeQuality();
        $options['fit'] = $this->computeFit($asset, $options['format']);
        $options['background'] = $this->computeBackground($asset);
        $options['gravity'] = $this->computeGravity($asset);
        $options['height'] = $this->owner->height;
        $options['width'] = $this->owner->width;

        return Collection::make($options)
            ->filter(fn($value) => $value !== null)
            ->all();
    }

    private function computeFormat(?Asset $asset): ?string
    {
        if ($this->owner->format === 'jpg' && $this->owner->interlace === 'none') {
            return 'baseline-jpeg';
        }

        $format = match ($this->owner->format) {
            'jpg' => 'jpeg',
            default => $this->owner->format,
        };

        return $format ?? ($asset?->kind === Asset::KIND_PDF ? 'auto' : null);
    }

    private function computeQuality(): int
    {
        // The gateway drops quality for PNG output, so Cloudflare keeps PNGs lossless like Craft.
        return $this->owner->quality ?: Craft::$app->getConfig()->getGeneral()->defaultImageQuality;
    }

    /**
     * @see https://developers.cloudflare.com/images/transform-images/transform-via-url/#fit
     */
    private function computeFit(?Asset $asset, ?string $format): string
    {
        if ($this->fit !== null) {
            return $this->fit;
        }

        return match ($this->owner->mode) {
            'fit' => $this->owner->upscale ? 'contain' : 'scale-down',
            'stretch' => 'squeeze',
            'letterbox' => 'pad',
            // Craft crops smaller images to the target aspect ratio without enlarging them.
            // Cloudflare's `crop` would return them at their original size and shape instead.
            // Rasterized PDFs and SVGs go through the Images binding, which doesn't accept `aspect-crop`.
            default => match (true) {
                (bool)$this->owner->upscale => 'cover',
                $this->isRasterized($asset, $format) => 'crop',
                default => 'aspect-crop',
            },
        };
    }

    private function isRasterized(?Asset $asset, ?string $format): bool
    {
        if ($asset?->kind === Asset::KIND_PDF) {
            return true;
        }

        return $asset !== null
            && strtolower($asset->getExtension()) === 'svg'
            && !in_array($format, [null, 'auto', 'json', 'svg'], true);
    }

    private function computeBackground(?Asset $asset): ?string
    {
        if ($this->background !== null) {
            return $this->background;
        }

        if ($this->owner->mode !== 'letterbox') {
            return null;
        }

        if ($this->owner->fill !== null && $this->owner->fill !== 'transparent') {
            return $this->owner->fill;
        }

        // Craft letterboxes with transparent white when no fill is set. That stays transparent only when
        // Craft's output format has an alpha channel; JPEG output flattens it to opaque white.
        // Cloudflare may serve AVIF or WebP instead, so match the color Craft's output would have.
        $outputFormat = strtolower($this->owner->format ?? $asset?->getExtension() ?? '');

        return in_array($outputFormat, ['png', 'webp', 'gif', 'avif'], true)
            ? 'rgba(255,255,255,0)'
            : '#FFFFFF';
    }

    private function computeGravity(?Asset $asset): array|null|string
    {
        if ($this->gravity !== null) {
            return $this->gravity;
        }

        // Like Craft, only crop mode uses the focal point; other modes use `position`.
        // A PDF can report a focal point but return none.
        $focalPoint = $this->owner->mode === 'crop' && $asset?->getHasFocalPoint() ? $asset->getFocalPoint() : null;

        if (is_array($focalPoint)) {
            // Center the crop on the focal point, clamped to the image bounds, like Craft's native transforms.
            // Cloudflare's default (`remainder`) keeps the point at the same relative position in the crop instead.
            return [...$focalPoint, 'mode' => 'box-center'];
        }

        if ($this->owner->position === 'center-center') {
            return null;
        }

        $parts = explode('-', $this->owner->position);

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
            Module::warning("Invalid position value: `{$this->owner->position}`");
            return null;
        }

        return [
            'x' => $x,
            'y' => $y,
        ];
    }
}
