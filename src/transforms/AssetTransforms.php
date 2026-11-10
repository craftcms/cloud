<?php

declare(strict_types=1);

namespace craft\cloud\transforms;

use CraftCms\Cms\Asset\AssetTransformers;
use Illuminate\Contracts\Container\Container;

/**
 * Registers the `craft-cloud` asset transform driver.
 */
class AssetTransforms
{
    /**
     * Registered in every environment, like the `craft-cloud` disk driver, so the same disk config works anywhere.
     */
    public static function register(Container $app): void
    {
        $extend = static function (AssetTransformers $transformers): void {
            $transformers->extend(
                CloudAssetTransformDriver::DRIVER,
                static fn(Container $app): CloudAssetTransformDriver => new CloudAssetTransformDriver(
                    $app->make(AssetTransformers::class),
                    new AssetCdn($_SERVER['CRAFT_CLOUD_SIGNING_KEY'] ?? ''),
                ),
            );
        };

        // Like ServiceProvider::callAfterResolving(), in case something resolved it early
        $app->afterResolving(AssetTransformers::class, $extend);

        if ($app->resolved(AssetTransformers::class)) {
            $extend($app->make(AssetTransformers::class));
        }
    }
}
