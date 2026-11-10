<?php

declare(strict_types=1);

namespace craft\cloud;

use craft\cloud\Console\StaticCacheCommand;
use craft\cloud\Http\Middleware\CacheResponse;
use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Utility\Utilities\ClearCaches;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
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
        Event::listen(ElementCachesInvalidated::class, [StaticCache::class, 'handleElementCachesInvalidated']);
        Event::listen(ElementSaved::class, [StaticCache::class, 'handleElementSaved']);
        Event::listen(ElementDeleted::class, [StaticCache::class, 'handleElementDeleted']);

        $flush = fn() => $this->app->make(StaticCache::class)->flush();
        $this->app->terminating($flush);
        // Queue workers don't terminate between jobs, and forget scoped instances before the next one.
        Event::listen([JobProcessed::class, JobExceptionOccurred::class], $flush);

        ClearCaches::add('craft-cloud-static-cache', [
            'label' => 'Craft Cloud static cache',
            'action' => fn() => $this->app->make(Gateway::class)->purgeAll(),
        ]);

        $this->commands(StaticCacheCommand::all());

        // Craft creates the `craft` group when it boots. Its `SetHeaders` middleware applies `{% expires %}` and
        // `noCache()`, so Cloud's middleware has to run outside it. Laravel's priority list then moves it outside the
        // `web` group's cookie and session middleware too, so it sees the cookies the response ends up with.
        $this->app->booted(function (): void {
            $this->app->make(Router::class)->prependMiddlewareToGroup('craft', CacheResponse::class);
            $this->app->make(Kernel::class)->addToMiddlewarePriorityBefore(EncryptCookies::class, CacheResponse::class);
        });
    }
}
