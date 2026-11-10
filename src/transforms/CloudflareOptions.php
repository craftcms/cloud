<?php

declare(strict_types=1);

namespace craft\cloud\transforms;

use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Maps validated transform parameters to the Cloudflare image options the Cloud gateway accepts.
 *
 * Core parameters map onto their Cloudflare equivalents. Options core has no equivalent for are declared as their
 * own parameters and passed through.
 *
 * @see https://developers.cloudflare.com/images/transform-images/transform-via-workers/#fetch-options
 */
class CloudflareOptions
{
    /**
     * Rules for the Cloudflare options that aren’t covered by core parameters. `page` is the gateway’s PDF page.
     *
     * @return array<string, non-empty-list<mixed>>
     */
    public static function rules(): array
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
            'flip' => ['string', 'in:h,v,hv'],
            'gamma' => ['numeric'],
            'gravity' => [Rule::anyOf([['string'], ['array']])],
            'metadata' => ['string', 'in:keep,copyright,none'],
            'page' => ['integer', 'min:1'],
            'rotate' => ['integer', 'in:0,90,180,270'],
            'saturation' => ['numeric'],
            'segment' => ['string', 'in:foreground'],
            'sharpen' => ['numeric', 'between:0,10'],
            'trim' => [Rule::anyOf([['string'], ['array']])],
            'zoom' => ['numeric', 'between:0,1'],
        ];
    }

    /**
     * Like the `craft` driver, crops use the focal point instead of `position`. An explicit `gravity` or
     * `background` wins over the values derived from `position` and `fill`.
     *
     * @param array<string, mixed> $parameters
     * @param array{x: float, y: float}|null $focalPoint
     * @return array<string, mixed>
     */
    public static function fromParameters(array $parameters, ?array $focalPoint = null): array
    {
        $mode = $parameters['mode'] ?? 'crop';
        $upscale = filter_var($parameters['upscale'] ?? true, FILTER_VALIDATE_BOOL);
        $options = Arr::only($parameters, ['width', 'height', 'quality', ...array_keys(self::rules())]);
        $options['format'] = match ($parameters['format'] ?? null) {
            'jpg' => ($parameters['interlace'] ?? 'none') === 'none' ? 'baseline-jpeg' : 'jpeg',
            default => $parameters['format'] ?? null,
        };
        $options['fit'] = match ($mode) {
            'fit' => $upscale ? 'contain' : 'scale-down',
            'stretch' => 'squeeze',
            'letterbox' => 'pad',
            default => $upscale ? 'cover' : 'crop',
        };
        $options['gravity'] ??=
            $mode === 'crop' && $focalPoint !== null
                ? $focalPoint
                : self::gravity($parameters['position'] ?? 'center-center');
        $options['background'] ??= $mode === 'letterbox' ? $parameters['fill'] ?? null : null;

        return array_filter($options, static fn(mixed $value): bool => $value !== null);
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
