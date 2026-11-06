<?php

declare(strict_types=1);

namespace craft\cloud\transforms;

use craft\cloud\signing\UrlSigner;
use CraftCms\Cms\Asset\AssetTransformDrivers;
use CraftCms\Cms\Asset\AssetTransformerRegistry;
use CraftCms\Cms\Asset\Data\AssetTransformer;
use CraftCms\Cms\Config\GeneralConfig;
use Illuminate\Contracts\Container\Container;

/**
 * Registers the `craft-cloud` asset transform driver and its `craftCloud` transformer profile.
 */
class AssetTransforms
{
    /**
     * The driver and profile are registered in every environment, so volumes and project config that reference the
     * profile stay valid in local development, where the driver falls back to Craft’s transformer.
     *
     * On Cloud, the profile also becomes the default transformer, unless the project chose another one.
     */
    public static function register(Container $app, bool $onCloud): void
    {
        $app->singleton(
            UrlSigner::class,
            static fn(): UrlSigner => new UrlSigner($_SERVER['CRAFT_CLOUD_SIGNING_KEY'] ?? ''),
        );

        self::afterResolving(
            $app,
            AssetTransformDrivers::class,
            static function (AssetTransformDrivers $drivers): void {
                $drivers->extend(CloudAssetTransformDriver::DRIVER, static fn(Container $app): CloudAssetTransformDriver => $app->make(CloudAssetTransformDriver::class));
            },
        );

        self::afterResolving(
            $app,
            AssetTransformerRegistry::class,
            static function (AssetTransformerRegistry $registry): void {
                $registry->register(new AssetTransformer([
                    'name' => 'Craft Cloud',
                    'handle' => CloudAssetTransformDriver::TRANSFORMER,
                    'driver' => CloudAssetTransformDriver::DRIVER,
                ]));
            },
        );

        if (!$onCloud) {
            return;
        }

        self::afterResolving($app, GeneralConfig::class, static function (GeneralConfig $config): void {
            if ($config->defaultAssetTransformer === 'craft') {
                $config->defaultAssetTransformer(CloudAssetTransformDriver::TRANSFORMER);
            }
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
}
