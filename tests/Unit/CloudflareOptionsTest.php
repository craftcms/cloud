<?php

declare(strict_types=1);

use craft\cloud\transforms\CloudflareOptions;

it('maps core parameters to Cloudflare options', function (array $parameters, array $expected): void {
    $options = CloudflareOptions::fromParameters($parameters);
    ksort($options);

    expect($options)->toBe($expected);
})->with([
    'crop with position' => [
        ['mode' => 'crop', 'position' => 'top-center', 'width' => 1200, 'height' => 750],
        ['fit' => 'cover', 'gravity' => ['x' => 0.5, 'y' => 0], 'height' => 750, 'width' => 1200],
    ],
    'crop without upscaling' => [
        ['mode' => 'crop', 'position' => 'bottom-right', 'upscale' => false, 'width' => 100],
        ['fit' => 'crop', 'gravity' => ['x' => 1, 'y' => 1], 'width' => 100],
    ],
    'center-center position leaves gravity to Cloudflare' => [
        ['position' => 'center-center', 'width' => 100],
        ['fit' => 'cover', 'width' => 100],
    ],
    'fit' => [
        ['mode' => 'fit', 'width' => 100],
        ['fit' => 'contain', 'width' => 100],
    ],
    'fit without upscaling' => [
        ['mode' => 'fit', 'upscale' => '0', 'width' => 100],
        ['fit' => 'scale-down', 'width' => 100],
    ],
    'stretch' => [
        ['mode' => 'stretch', 'width' => 100, 'height' => 50],
        ['fit' => 'squeeze', 'height' => 50, 'width' => 100],
    ],
    'letterbox with fill' => [
        ['mode' => 'letterbox', 'fill' => '#ff0000', 'width' => 100],
        ['background' => '#ff0000', 'fit' => 'pad', 'width' => 100],
    ],
    'letterbox without fill' => [
        ['mode' => 'letterbox', 'width' => 100],
        ['fit' => 'pad', 'width' => 100],
    ],
    'fill outside letterbox' => [
        ['fill' => '#ff0000', 'mode' => 'crop', 'width' => 100],
        ['fit' => 'cover', 'width' => 100],
    ],
    'jpg without interlacing' => [
        ['format' => 'jpg', 'interlace' => 'none', 'width' => 100],
        ['fit' => 'cover', 'format' => 'baseline-jpeg', 'width' => 100],
    ],
    'interlaced jpg' => [
        ['format' => 'jpg', 'interlace' => 'line', 'width' => 100],
        ['fit' => 'cover', 'format' => 'jpeg', 'width' => 100],
    ],
    'other formats' => [
        ['format' => 'webp', 'interlace' => 'line', 'width' => 100],
        ['fit' => 'cover', 'format' => 'webp', 'width' => 100],
    ],
    'quality' => [
        ['quality' => 60, 'width' => 100],
        ['fit' => 'cover', 'quality' => 60, 'width' => 100],
    ],
    'Cloudflare-only options' => [
        ['blur' => 5, 'gravity' => 'face', 'width' => 200],
        ['blur' => 5, 'fit' => 'cover', 'gravity' => 'face', 'width' => 200],
    ],
    'explicit background wins over fill' => [
        ['background' => '#000', 'fill' => '#fff', 'mode' => 'letterbox'],
        ['background' => '#000', 'fit' => 'pad'],
    ],
    'null parameters from named transforms are dropped' => [
        ['fill' => null, 'format' => null, 'height' => null, 'mode' => 'crop', 'quality' => null, 'width' => 300],
        ['fit' => 'cover', 'width' => 300],
    ],
    'undeclared parameters are not forwarded' => [
        ['fit' => 'contain', 'origin' => 'https://evil.test', 'width' => 100],
        ['fit' => 'cover', 'width' => 100],
    ],
]);

it('uses the focal point for crops, like the craft driver', function (string $mode, ?array $expected): void {
    $options = CloudflareOptions::fromParameters(['mode' => $mode, 'position' => 'top-left', 'width' => 100], [
        'x' => 0.474,
        'y' => 0.3064,
    ]);

    expect($options['gravity'] ?? null)->toBe($expected);
})->with([
    'crop' => ['crop', ['x' => 0.474, 'y' => 0.3064]],
    'fit' => ['fit', ['x' => 0, 'y' => 0]],
]);

it('prefers explicit gravity over the focal point', function (): void {
    $options = CloudflareOptions::fromParameters(['gravity' => ['x' => 0.57, 'y' => 0.7707], 'width' => 100], [
        'x' => 0.474,
        'y' => 0.3064,
    ]);

    expect($options['gravity'])->toBe(['x' => 0.57, 'y' => 0.7707]);
});
