<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\Config\GeneralConfig;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The `craft-cloud` disk driver: an S3 disk on the project's Craft Cloud bucket, with CDN URLs.
 *
 * ```php
 * // config/filesystems.php
 * 'images' => ['driver' => 'craft-cloud', 'prefix' => 'images'],
 * ```
 *
 * The disk is in the environment's `assets` folder, or another one with `root`, such as `storage`. `prefix` and other
 * options work as they do on any Laravel disk. The bucket, credentials and URL come from the `CRAFT_CLOUD_*` variables,
 * which Craft Cloud sets. Resolving the disk without them throws, so the driver never stands in for another disk.
 */
class Disks
{
    public const string DRIVER = 'craft-cloud';

    /** Upload sessions and temporary asset uploads on Craft Cloud. */
    public const string TMP = 'craft-cloud-tmp';

    public static function extend(Container $app): void
    {
        self::afterResolving($app, 'filesystem', static function (FilesystemManager $manager): void {
            $manager->extend(self::DRIVER, static fn(mixed $app, array $config) => $manager->build(Disks::config(
                $config,
            )));
        });
    }

    /**
     * Stages upload sessions and temporary asset uploads in the bucket, unless the project chose disks for them,
     * since the function's own disk doesn't outlive an invocation. Core redefines its own `craft-tmp` and
     * `craft-asset-temp` disks when it boots, so this changes Craft's settings rather than those disks.
     */
    public static function stageUploads(Container $app): void
    {
        if (!Config::has('filesystems.disks.' . self::TMP)) {
            Config::set('filesystems.disks.' . self::TMP, ['driver' => self::DRIVER, 'root' => 'storage/tmp']);
        }

        self::afterResolving($app, GeneralConfig::class, static function (GeneralConfig $config): void {
            $config->uploadSessionDisk ??= self::TMP;
            $config->tempAssetUploadDisk ??= self::TMP;
        });
    }

    /**
     * Returns the `s3` disk config for a `craft-cloud` disk config.
     */
    public static function config(array $config): array
    {
        $required = [
            'CRAFT_CLOUD_PROJECT_ID' => $_SERVER['CRAFT_CLOUD_PROJECT_ID'] ?? null,
            'CRAFT_CLOUD_ENVIRONMENT_ID' => $_SERVER['CRAFT_CLOUD_ENVIRONMENT_ID'] ?? null,
            'CRAFT_CLOUD_REGION or AWS_REGION' => $_SERVER['CRAFT_CLOUD_REGION'] ?? $_SERVER['AWS_REGION'] ?? null,
        ];
        $missing = array_keys(array_filter($required, static fn(?string $value): bool => !$value));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf('The `craft-cloud` disk driver needs %s to be set.', implode(
                ', ',
                $missing,
            )));
        }

        [$bucket, $environmentId, $region] = array_values($required);
        $endpoint = $_SERVER['CRAFT_CLOUD_STORAGE_ENDPOINT'] ?? null;

        // Without an access key, the SDK finds credentials itself, such as the function's role
        $credentials = array_filter([
            'key' => $_SERVER['CRAFT_CLOUD_ACCESS_KEY'] ?? null,
            'secret' => $_SERVER['CRAFT_CLOUD_ACCESS_SECRET'] ?? null,
            'token' => $_SERVER['CRAFT_CLOUD_ACCESS_TOKEN'] ?? null,
        ]);

        return [
            'visibility' => 'private',
            ...$config,
            ...$credentials,
            'driver' => 's3',
            'bucket' => $bucket,
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => $endpoint !== null,
            'root' => $environmentId . '/' . trim($config['root'] ?? 'assets', characters: '/'),
            // Laravel appends the root and prefix to S3 disks' URLs
            'url' => rtrim($_SERVER['CRAFT_CLOUD_CDN_BASE_URL'] ?? 'https://cdn.craft.cloud', characters: '/'),
        ];
    }

    /**
     * Like {@see \Illuminate\Support\ServiceProvider::callAfterResolving()}, in case something resolved it early.
     */
    private static function afterResolving(Container $app, string $abstract, callable $callback): void
    {
        $app->afterResolving($abstract, $callback);

        if ($app->resolved($abstract)) {
            $callback($app->make($abstract));
        }
    }
}
