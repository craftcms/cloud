<?php

declare(strict_types=1);

namespace craft\cloud;

use craft\cloud\Http\Middleware\CacheDefaults;
use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Http\Events\CacheControlApplied;
use CraftCms\Cms\Http\Middleware\ApplyCacheControl;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class StaticCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(StaticCache::class);
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
        Event::listen(CacheControlApplied::class, fn(CacheControlApplied $event) => $this->app
            ->make(StaticCache::class)
            ->apply($event));
        Event::listen(ElementCachesInvalidated::class, fn(ElementCachesInvalidated $event) => $this->app
            ->make(StaticCache::class)
            ->invalidate($event));
        $this->app->terminating(fn() => $this->app->make(StaticCache::class)->flush());

        $this->app->booted(function (): void {
            $this->app->make(Router::class)->pushMiddlewareToGroup('craft.web', CacheDefaults::class);
            $this->app->make(Kernel::class)->addToMiddlewarePriorityAfter(
                ApplyCacheControl::class,
                CacheDefaults::class,
            );
        });
    }
}
