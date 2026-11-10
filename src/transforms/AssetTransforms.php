<?php

declare(strict_types=1);

namespace craft\cloud\transforms;

use craft\cloud\signing\UrlSigner;
use CraftCms\Cms\Asset\AssetTransformers;
use Illuminate\Contracts\Container\Container;

/**
 * Registers the `craft-cloud` asset transform driver.
 */
class AssetTransforms
{
    /**
     * The driver is registered in every environment, so disks that set `'asset_transformer' => 'craft-cloud'` stay
     * valid in local development, where the driver hands files to the `craft` driver.
     */
    public static function register(Container $app): void
    {
        $app->singleton(
            UrlSigner::class,
            static fn(): UrlSigner => new UrlSigner($_SERVER['CRAFT_CLOUD_SIGNING_KEY'] ?? ''),
        );

        $extend = static function (AssetTransformers $transformers): void {
            $transformers->extend(CloudAssetTransformDriver::DRIVER, static fn(Container $app): CloudAssetTransformDriver => $app->make(CloudAssetTransformDriver::class));
        };

        // Like ServiceProvider::callAfterResolving(), in case something resolved it early
        $app->afterResolving(AssetTransformers::class, $extend);

        if ($app->resolved(AssetTransformers::class)) {
            $extend($app->make(AssetTransformers::class));
        }
    }
}
