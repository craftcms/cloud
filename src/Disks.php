<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\Config\GeneralConfig;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Config;

/**
 * Registers the `craft-cloud` disk driver (see {@see CloudDisk}) and Craft Cloud's default disks.
 */
class Disks
{
    public const string DRIVER = 'craft-cloud';

    /** Assets, in `{env}/assets`, like 3.x's `AssetsFs`. */
    public const string ASSETS = 'craft-cloud';

    /** Upload session staging, in `{env}/storage/tmp/uploads`. */
    public const string UPLOADS = 'craft-cloud-uploads';

    /** Temporary asset uploads, in `{env}/storage/tmp`, like 3.x's `TmpFs`. */
    public const string TEMP = 'craft-cloud-temp';

    /**
     * Registers the driver and the default disks. Projects can redefine any of the disks in `config/filesystems.php`.
     *
     * On Cloud, upload sessions and temporary asset uploads also default to bucket disks, since the function's own
     * storage doesn't outlive an invocation. Core redefines its own `craft-tmp` and `craft-asset-temp` disks when it
     * boots, so this changes which disks Craft uses, rather than redefining those.
     */
    public static function register(Container $app, bool $onCloud): void
    {
        self::afterResolving($app, 'filesystem', static function (FilesystemManager $manager) use ($onCloud): void {
            $manager->extend(self::DRIVER, static fn(mixed $app, array $config) => $manager->build(CloudDisk::config(
                $config,
                $onCloud,
            )));
        });

        self::setDefault(self::ASSETS, ['driver' => self::DRIVER]);

        if (!$onCloud) {
            return;
        }

        self::setDefault(self::UPLOADS, ['driver' => self::DRIVER, 'root' => 'storage/tmp/uploads']);
        self::setDefault(self::TEMP, ['driver' => self::DRIVER, 'root' => 'storage/tmp']);

        self::afterResolving($app, GeneralConfig::class, static function (GeneralConfig $config): void {
            $config->uploadSessionDisk ??= self::UPLOADS;
            $config->tempAssetUploadDisk ??= self::TEMP;
        });
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

    private static function setDefault(string $name, array $config): void
    {
        if (!Config::has("filesystems.disks.{$name}")) {
            Config::set("filesystems.disks.{$name}", $config);
        }
    }
}
