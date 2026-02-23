<?php

namespace craft\cloud;

use Craft;
use Illuminate\Support\Collection;
use League\Uri\Components\Query;

/**
 * @see https://developers.cloudflare.com/images/transform-images/transform-via-workers/#fetch-options
 * @see https://github.com/cloudflare/workerd/blob/main/types/defines/cf.d.ts
 */
class ImageTransform extends \craft\models\ImageTransform
{
    public ?bool $anim = null;
    public ?string $background = null;

    /**
     * @var int|null Blur radius (1-250)
     */
    public ?int $blur = null;

    /**
     * @var array{color: string, width: int}|array{color: string, top: int, right: int, bottom: int, left: int}|null Border configuration
     */
    public ?array $border = null;

    /**
     * @var float|null Brightness adjustment (-1.0 to 1.0)
     */
    public ?float $brightness = null;

    /**
     * @var string|null Compression level
     */
    public ?string $compression = null;

    /**
     * @var float|null Contrast adjustment (-1.0 to 1.0)
     */
    public ?float $contrast = null;

    /**
     * @var float|null Device pixel ratio (DPR)
     */
    public ?float $dpr = null;

    /**
     * @var array{url: string, opacity?: float, repeat?: true|'x'|'y', top?: int, left?: int, bottom?: int, right?: int, width?: int, height?: int, fit?: 'scale-down'|'contain'|'cover'|'crop'|'pad'|'squeeze', gravity?: 'face'|'left'|'right'|'top'|'bottom'|'center'|'auto'|'entropy'|array{x?: float, y?: float, mode?: 'remainder'|'box-center'}, background?: string, rotate?: 0|90|180|270|360, segment?: 'foreground'}[]|null Draw overlays
     */
    public ?array $draw = null;

    /**
     * @var string|null Fit mode override (Cloudflare-specific)
     */
    public ?string $fit = null;
    public ?string $format = null;

    /**
     * @var string|null Flip direction ('horizontal', 'vertical', 'both')
     */
    public ?string $flip = null;

    /**
     * @var float|null Gamma correction
     */
    public ?float $gamma = null;

    /**
     * @var 'face'|'left'|'right'|'top'|'bottom'|'center'|'auto'|'entropy'|array{x?: float, y?: float, mode?: 'remainder'|'box-center'}|null Gravity/focus point
     */
    public null|string|array $gravity = null;

    public ?int $height;

    /**
     * @var string|null Metadata handling ('keep', 'copyright', 'none')
     */
    public ?string $metadata = null;

    /**
     * @var string|null Error handling ('redirect')
     */
    public ?string $onerror = null;

    /**
     * @var string|null Origin authentication
     */
    public ?string $originAuth = null;

    /**
     * @var int|null Rotation angle (0, 90, 180, 270, 360)
     */
    public ?int $rotate = null;

    /**
     * @var float|null Saturation adjustment (-1.0 to 1.0)
     */
    public ?float $saturation = null;

    /**
     * @var string|null Segment to extract ('foreground', 'background')
     */
    public ?string $segment = null;

    /**
     * @var float|null Sharpen amount (0.0 to 10.0)
     */
    public ?float $sharpen = null;

    /**
     * @var 'border'|array{top?: int, bottom?: int, left?: int, right?: int, width?: int, height?: int, border?: bool|array{color?: string, tolerance?: int, keep?: int}}|null
     */
    public null|string|array $trim = null;

    public ?int $width;
    public ?float $zoom = null;

    /**
     * @inheritdoc
     */
    public function fields(): array
    {
        $this->normalize();
        return parent::fields();
    }

    public function normalize(): static
    {
        $this->format = $this->computeFormat();
        $this->fit = $this->computeFit();
        $this->background = $this->computeBackground();
        $this->gravity = $this->computeGravity();
        return $this;
    }

    public function toOptions(): array
    {
        // Get public properties declared in this class (including overridden ones)
        $reflection = new \ReflectionClass($this);
        $localProps = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() === self::class) {
                $localProps[] = $property->getName();
            }
        }

        return Collection::make($this->toArray($localProps))
            ->filter(fn($value, $key) => $value !== null)
            ->all();
    }

    /**
     * Compute the Cloudflare format from the base format and interlace settings.
     *
     * @return string|null
     */
    private function computeFormat(): ?string
    {
        if ($this->format === 'jpg' && $this->interlace === 'none') {
            return 'baseline-jpeg';
        }

        return match ($this->format) {
            'jpg' => 'jpeg',
            default => $this->format,
        };
    }

    /**
     * Compute the Cloudflare fit mode from the base mode and upscale settings.
     *
     * @see https://developers.cloudflare.com/images/transform-images/transform-via-url/#fit
     * @return string
     */
    private function computeFit(): string
    {
        if ($this->fit !== null) {
            return $this->fit;
        }

        // Cloudflare doesn't have an exact match to `stretch`.
        // `cover` is close, but will crop instead of stretching.
        return match ($this->mode) {
            'fit' => $this->upscale ? 'contain' : 'scale-down',
            'stretch' => 'cover',
            'letterbox' => 'pad',
            default => $this->upscale ? 'cover' : 'crop',
        };
    }

    /**
     * Compute the Cloudflare background color from the base mode and fill settings.
     *
     * @return string|null
     */
    private function computeBackground(): ?string
    {
        if ($this->background !== null) {
            return $this->background;
        }

        return $this->mode === 'letterbox'
            ? $this->fill ?? '#FFFFFF'
            : null;
    }

    /**
     * Compute the Cloudflare gravity from the base position setting.
     *
     * @return array{x: float, y: float}|null|'face'
     */
    private function computeGravity(): array|null|string
    {
        if ($this->gravity !== null) {
            return $this->gravity;
        }

        if ($this->position === 'center-center') {
            return null;
        }

        // TODO: maybe just do this in Craft
        $parts = explode('-', $this->position);

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
            Craft::warning("Invalid position value: `{$this->position}`", __METHOD__);
            return null;
        }

        return [
            'x' => $x,
            'y' => $y,
        ];
    }
}
