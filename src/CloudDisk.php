<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\Support\Env;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Turns `craft-cloud` disk configs into `s3` disk configs on Cloud, or `local` disk configs elsewhere.
 *
 * On Cloud, disks are in the project's bucket, under the environment's prefix, with CDN URLs. Off Cloud, they're in
 * `public/uploads`, like 3.x's local filesystem fallback, so the same config works in local development:
 *
 * ```php
 * 'images' => [
 *     'driver' => 'craft-cloud',
 *     'subpath' => 'images', // optional
 *     'url' => 'https://cdn.example.com', // optional; the CDN's base URL, defaults to `https://cdn.craft.cloud`
 * ],
 * ```
 *
 * `root` is relative to the environment's prefix, and defaults to `assets`. Other options, such as `prefix` from
 * `scoped` disks, are passed through.
 */
class CloudDisk
{
    private const string CDN_BASE_URL = 'https://cdn.craft.cloud';

    public static function config(array $config, bool $onCloud): array
    {
        $subpath = Env::parse($config['subpath'] ?? null);
        $rest = Arr::except($config, ['driver', 'root', 'subpath', 'url', 'expires']);

        // Only asset disks are registered off Cloud, so everything goes in the webroot
        if (!$onCloud) {
            return [
                ...$rest,
                'driver' => 'local',
                'root' => public_path(self::join('uploads', $subpath)),
                'url' => '/' . self::join('uploads', $subpath),
            ];
        }

        $bucket = $_SERVER['CRAFT_CLOUD_PROJECT_ID'] ?? null;
        $environmentId = $_SERVER['CRAFT_CLOUD_ENVIRONMENT_ID'] ?? null;

        if (!$bucket || !$environmentId) {
            throw new InvalidArgumentException(
                'Craft Cloud disks need CRAFT_CLOUD_PROJECT_ID and CRAFT_CLOUD_ENVIRONMENT_ID to be set.',
            );
        }

        $endpoint = $_SERVER['CRAFT_CLOUD_STORAGE_ENDPOINT'] ?? null;

        // Without an access key, the SDK uses the function's own credentials
        $s3 = array_filter(
            [
                'bucket' => $bucket,
                'region' => $_SERVER['CRAFT_CLOUD_REGION'] ?? $_SERVER['AWS_REGION'] ?? null,
                'key' => $_SERVER['CRAFT_CLOUD_ACCESS_KEY'] ?? null,
                'secret' => $_SERVER['CRAFT_CLOUD_ACCESS_SECRET'] ?? null,
                'token' => $_SERVER['CRAFT_CLOUD_ACCESS_TOKEN'] ?? null,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => $endpoint !== null,
            ],
            static fn(mixed $value): bool => $value !== null,
        );

        return [
            // Like 3.x, objects are private
            'visibility' => 'private',
            ...$s3,
            ...$rest,
            'driver' => 's3',
            'root' => self::join($environmentId, $config['root'] ?? 'assets', $subpath),
            // Laravel appends the root to S3 disks' URLs, so this is just the CDN's base URL
            'url' => rtrim(
                $config['url'] ?? $_SERVER['CRAFT_CLOUD_CDN_BASE_URL'] ?? self::CDN_BASE_URL,
                characters: '/',
            ),
        ];
    }

    private static function join(?string ...$segments): string
    {
        return implode('/', array_filter(
            array_map(static fn(?string $segment): string => trim((string) $segment, characters: '/'), $segments),
            static fn(string $segment): bool => $segment !== '',
        ));
    }
}
