<?php

declare(strict_types=1);

namespace craft\cloud;

use craft\cloud\Console\StaticCacheCommand;
use craft\cloud\Http\Middleware\CacheResponse;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Utility\Utilities\ClearCaches;
use CraftCms\HttpCache\HttpCacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class StaticCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Config::set('cloud', array_replace(
            [
                'environment_id' => $_SERVER['CRAFT_CLOUD_ENVIRONMENT_ID'] ?? null,
                'signing_key' => $_SERVER['CRAFT_CLOUD_SIGNING_KEY'] ?? null,
            ],
            Config::get('cloud', []),
        ));
    }

    public function boot(): void
    {
        // Cloud's gateway is the page cache, and its CDN reads `Cache-Tag`.
        Config::set('http-cache.default', StaticCache::CLIENT);
        // The package only invalidates element tags on clients listed in its config.
        Config::set('http-cache.clients.' . StaticCache::CLIENT, Config::get('http-cache.clients.'
        . StaticCache::CLIENT, []));
        Config::set('http-cache.tags.formatter', CacheTagHeaderFormatter::class);
        Config::set('http-cache.tags.formatter_options', []);
        $this->app->make(HttpCacheManager::class)->extend(
            'cloud',
            static fn(Application $app, array $config) => new Gateway(
                $app->make(CacheTagEncoder::class),
                softPurge: (bool) ($config['soft_purge'] ?? false),
            ),
        );

        Event::listen(ElementSaved::class, [StaticCache::class, 'handleElementSaved']);
        Event::listen(ElementDeleted::class, [StaticCache::class, 'handleElementDeleted']);

        ClearCaches::add('craft-cloud-static-cache', [
            'label' => 'Craft Cloud static cache',
            'action' => fn() => $this->app
                ->make(HttpCacheManager::class)
                ->invalidator(StaticCache::CLIENT)
                ->clearCache()
                ->flush(),
        ]);

        $this->commands(StaticCacheCommand::all());

        // First, so it sees the final response, with its lifetimes capped and its cookies added.
        /** @var Kernel $kernel */
        $kernel = $this->app->make(KernelContract::class);
        $kernel->prependMiddleware(CacheResponse::class);
    }
}
