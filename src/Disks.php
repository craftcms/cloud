<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Filesystem\Events\UploadSessionStarting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

/**
 * The project's bucket, as Laravel disks: one for assets, which volumes can use, and one for staging uploads.
 *
 * They share their credentials, so uploads go from the browser straight to the bucket, and are copied into Craft
 * Cloud volumes without leaving it.
 */
class Disks
{
    public const ASSETS = 'craft-cloud';
    public const UPLOADS = 'craft-cloud-uploads';
    private const CDN_BASE_URL = 'https://cdn.craft.cloud';

    /**
     * Registers the disks, if the bucket is known.
     */
    public static function configure(): void
    {
        $bucket = $_SERVER['CRAFT_CLOUD_PROJECT_ID'] ?? null;
        $environmentId = $_SERVER['CRAFT_CLOUD_ENVIRONMENT_ID'] ?? null;
        $region = $_SERVER['CRAFT_CLOUD_REGION'] ?? $_SERVER['AWS_REGION'] ?? null;

        if (!$bucket || !$environmentId || !$region) {
            return;
        }

        $endpoint = $_SERVER['CRAFT_CLOUD_STORAGE_ENDPOINT'] ?? null;
        // Without an access key, the SDK uses the function's own credentials
        $disk = array_filter(
            [
                'driver' => 's3',
                'bucket' => $bucket,
                'region' => $region,
                'key' => $_SERVER['CRAFT_CLOUD_ACCESS_KEY'] ?? null,
                'secret' => $_SERVER['CRAFT_CLOUD_ACCESS_SECRET'] ?? null,
                'token' => $_SERVER['CRAFT_CLOUD_ACCESS_TOKEN'] ?? null,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => $endpoint ? true : null,
                'visibility' => 'private',
                'throw' => true,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
        $cdnBaseUrl = rtrim($_SERVER['CRAFT_CLOUD_CDN_BASE_URL'] ?? self::CDN_BASE_URL, characters: '/');

        Config::set('filesystems.disks.' . self::ASSETS, [
            ...$disk,
            'root' => "{$environmentId}/assets",
            'url' => "{$cdnBaseUrl}/{$environmentId}/assets",
        ]);
        Config::set('filesystems.disks.' . self::UPLOADS, [
            ...$disk,
            'root' => "{$environmentId}/storage/tmp/uploads",
        ]);
    }

    /**
     * Stages upload sessions on the uploads disk, unless the project chooses its own disk for them.
     */
    public static function stageUploads(): void
    {
        if (!Config::has('filesystems.disks.' . self::UPLOADS)) {
            return;
        }

        Event::listen(UploadSessionStarting::class, static function (UploadSessionStarting $event): void {
            if (Cms::config()->uploadSessionDisk === null) {
                $event->disk ??= self::UPLOADS;
            }
        });
    }
}
