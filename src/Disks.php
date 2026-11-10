<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\Config\GeneralConfig;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

/**
 * Craft Cloud storage, as Laravel disks on the project's bucket.
 *
 * Volumes use the `craft-cloud` disk, with their own subpaths. On Craft Cloud, its connection (driver, bucket,
 * credentials, root, URL and visibility) is set to the bucket's `{env}/assets` prefix and the CDN. Its other options,
 * such as `asset_transformer`, are kept. Elsewhere, it's whatever the project defines:
 *
 * ```php
 * // config/filesystems.php
 * 'craft-cloud' => [
 *     'driver' => 'local',
 *     'root' => public_path('uploads'),
 *     'url' => '/uploads',
 * ],
 * ```
 *
 * Projects upgraded from Craft 5 need a disk for each Craft Cloud filesystem handle, which can scope `craft-cloud`:
 *
 * ```php
 * 'images' => ['driver' => 'scoped', 'disk' => 'craft-cloud', 'prefix' => 'images'],
 * ```
 */
class Disks
{
    public const string ASSETS = 'craft-cloud';

    /** Upload sessions and temporary asset uploads, in `{env}/storage/tmp`, which outlive a single invocation. */
    public const string TMP = 'craft-cloud-tmp';

    private const array CONNECTION = [
        'driver',
        'bucket',
        'region',
        'key',
        'secret',
        'token',
        'endpoint',
        'use_path_style_endpoint',
        'root',
        'url',
        'visibility',
    ];

    public static function configure(Container $app): void
    {
        $bucket = $_SERVER['CRAFT_CLOUD_PROJECT_ID'] ?? null;
        $environmentId = $_SERVER['CRAFT_CLOUD_ENVIRONMENT_ID'] ?? null;

        if (!$bucket || !$environmentId) {
            return;
        }

        $endpoint = $_SERVER['CRAFT_CLOUD_STORAGE_ENDPOINT'] ?? null;

        // Without an access key, the SDK uses the function's own credentials
        $connection = array_filter(
            [
                'driver' => 's3',
                'bucket' => $bucket,
                'region' => $_SERVER['CRAFT_CLOUD_REGION'] ?? $_SERVER['AWS_REGION'] ?? null,
                'key' => $_SERVER['CRAFT_CLOUD_ACCESS_KEY'] ?? null,
                'secret' => $_SERVER['CRAFT_CLOUD_ACCESS_SECRET'] ?? null,
                'token' => $_SERVER['CRAFT_CLOUD_ACCESS_TOKEN'] ?? null,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => $endpoint !== null,
                'visibility' => 'private',
            ],
            static fn(mixed $value): bool => $value !== null,
        );
        $cdnBaseUrl = rtrim($_SERVER['CRAFT_CLOUD_CDN_BASE_URL'] ?? 'https://cdn.craft.cloud', characters: '/');

        Config::set('filesystems.disks.' . self::ASSETS, [
            ...Arr::except((array) Config::get('filesystems.disks.' . self::ASSETS), self::CONNECTION),
            ...$connection,
            'root' => "{$environmentId}/assets",
            // Laravel appends the root to S3 disks' URLs
            'url' => $cdnBaseUrl,
        ]);
        Config::set('filesystems.disks.' . self::TMP, [...$connection, 'root' => "{$environmentId}/storage/tmp"]);

        // The function's own disk doesn't outlive an invocation, and core redefines `craft-tmp` and
        // `craft-asset-temp` when it boots, so point Craft at the bucket unless the project chose disks itself
        $setTmpDisks = static function (GeneralConfig $config): void {
            $config->uploadSessionDisk ??= self::TMP;
            $config->tempAssetUploadDisk ??= self::TMP;
        };
        $app->afterResolving(GeneralConfig::class, $setTmpDisks);

        if ($app->resolved(GeneralConfig::class)) {
            $setTmpDisks($app->make(GeneralConfig::class));
        }
    }
}
