<?php

namespace craft\cloud\imagetransforms;

use Craft;
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
     * @var 'scale-down'|'contain'|'cover'|'crop'|'pad'|'squeeze'|null
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
     * @var 'auto'|'face'|'left'|'right'|'top'|'bottom'|array{x?: float, y?: float}|null
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

    public function toOptions(array|string|null $gravity = null): array
    {
        $reflection = new \ReflectionClass($this);

        $options = Collection::make($reflection->getProperties(\ReflectionProperty::IS_PUBLIC))
            ->filter(fn($property) => $property->getDeclaringClass()->getName() === self::class)
            ->mapWithKeys(fn($property) => [$property->getName() => $property->getValue($this)])
            ->all();

        // Compute derived Cloudflare values from Craft's base transform settings,
        // without mutating the model (so the same instance can be safely reused).
        $options['format'] = $this->computeFormat();
        $options['fit'] = $this->computeFit();
        $options['background'] = $this->computeBackground();
        $options['gravity'] ??= $gravity ?? $this->computeGravity();
        $options['height'] = $this->owner->height;
        $options['width'] = $this->owner->width;

        return Collection::make($options)
            ->filter(fn($value) => $value !== null)
            ->all();
    }

    private function computeFormat(): ?string
    {
        if ($this->owner->format === 'jpg' && $this->owner->interlace === 'none') {
            return 'baseline-jpeg';
        }

        return match ($this->owner->format) {
            'jpg' => 'jpeg',
            default => $this->owner->format,
        };
    }

    /**
     * @see https://developers.cloudflare.com/images/transform-images/transform-via-url/#fit
     */
    private function computeFit(): string
    {
        if ($this->fit !== null) {
            return $this->fit;
        }

        return match ($this->owner->mode) {
            'fit' => $this->owner->upscale ? 'contain' : 'scale-down',
            'stretch' => 'squeeze',
            'letterbox' => 'pad',
            default => $this->owner->upscale ? 'cover' : 'crop',
        };
    }

    private function computeBackground(): ?string
    {
        if ($this->background !== null) {
            return $this->background;
        }

        return $this->owner->mode === 'letterbox'
            ? $this->owner->fill ?? '#FFFFFF'
            : null;
    }

    /**
     * @return array{x: float, y: float}|null|'face'
     */
    private function computeGravity(): array|null|string
    {
        if ($this->gravity !== null) {
            return $this->gravity;
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
            Craft::warning("Invalid position value: `{$this->owner->position}`", __METHOD__);
            return null;
        }

        return [
            'x' => $x,
            'y' => $y,
        ];
    }
}
