<?php

declare(strict_types=1);

namespace craft\cloud\transforms;

use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Maps Craft transform parameters to Cloudflare Image Resizing options.
 *
 * @see https://developers.cloudflare.com/images/transform-images/transform-via-workers/#fetch-options
 */
class CloudflareOptions
{
    /**
     * Validation rules for the Cloudflare options a transform can set directly.
     *
     * `quality` is a core parameter; it's listed so it's forwarded to Cloudflare.
     *
     * @return array<string, non-empty-list<mixed>>
     */
    public static function parameterRules(): array
    {
        return [
            'anim' => ['boolean'],
            'background' => ['string'],
            'blur' => ['integer', 'between:1,250'],
            'border' => ['array'],
            'brightness' => ['numeric'],
            'compression' => ['string', 'in:fast'],
            'contrast' => ['numeric'],
            'dpr' => ['numeric'],
            'draw' => ['array'],
            'fit' => ['string', 'in:scale-down,contain,cover,crop,pad,squeeze'],
            'flip' => ['string', 'in:h,v,hv'],
            'gamma' => ['numeric'],
            'gravity' => [Rule::anyOf([['string'], ['array']])],
            'metadata' => ['string', 'in:keep,copyright,none'],
            'page' => ['integer', 'min:1'],
            'quality' => ['integer', 'between:1,100'],
            'rotate' => ['integer', 'in:0,90,180,270'],
            'saturation' => ['numeric'],
            'segment' => ['string', 'in:foreground'],
            'sharpen' => ['numeric', 'between:0,10'],
            'trim' => [Rule::anyOf([['string'], ['array']])],
            'zoom' => ['numeric', 'between:0,1'],
        ];
    }

    /**
     * Explicit Cloudflare options win over values derived from Craft's mode,
     * position, fill, format, and interlace parameters. The focal point is used
     * for gravity when the transform doesn't set one.
     *
     * @param array<string, mixed> $parameters Validated transform parameters
     * @param array{x: float, y: float}|null $focalPoint
     * @return array<string, mixed>
     */
    public static function fromParameters(array $parameters, ?array $focalPoint = null): array
    {
        $options = Arr::only($parameters, array_keys(self::parameterRules()));
        $mode = $parameters['mode'] ?? 'crop';
        $upscale = filter_var($parameters['upscale'] ?? true, FILTER_VALIDATE_BOOL);

        $options['width'] = $parameters['width'] ?? null;
        $options['height'] = $parameters['height'] ?? null;
        $options['format'] = self::format($parameters['format'] ?? null, $parameters['interlace'] ?? 'none');
        $options['fit'] ??= match ($mode) {
            'fit' => $upscale ? 'contain' : 'scale-down',
            'stretch' => 'squeeze',
            'letterbox' => 'pad',
            default => $upscale ? 'cover' : 'crop',
        };
        $options['background'] ??= $mode === 'letterbox' ? $parameters['fill'] ?? '#FFFFFF' : null;
        $options['gravity'] ??= $focalPoint ?? self::gravity($parameters['position'] ?? 'center-center');

        $options = array_filter($options, fn(mixed $value): bool => $value !== null);
        ksort($options);

        return $options;
    }

    private static function format(?string $format, string $interlace): ?string
    {
        if ($format !== 'jpg') {
            return $format;
        }

        return $interlace === 'none' ? 'baseline-jpeg' : 'jpeg';
    }

    /**
     * @return array{x: float|int, y: float|int}|null
     */
    private static function gravity(string $position): ?array
    {
        if ($position === 'center-center') {
            return null;
        }

        [$y, $x] = explode('-', $position);

        return [
            'x' => ['left' => 0, 'center' => 0.5, 'right' => 1][$x],
            'y' => ['top' => 0, 'center' => 0.5, 'bottom' => 1][$y],
        ];
    }
}
