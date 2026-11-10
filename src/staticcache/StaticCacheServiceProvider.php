<?php

declare(strict_types=1);

namespace craft\cloud\staticcache;

use craft\cloud\signing\RequestSigner;
use CraftCms\Cms\Utility\Utilities\ClearCaches;
use CraftCms\DependencyAwareCache\Events\TagsInvalidated;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class StaticCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(StaticCache::class);
        $this->app->singleton(
            RequestSigner::class,
            static fn(): RequestSigner => new RequestSigner($_SERVER['CRAFT_CLOUD_SIGNING_KEY'] ?? ''),
        );
    }

    public function boot(): void
    {
        Event::listen(TagsInvalidated::class, [StaticCache::class, 'handleTagsInvalidated']);

        $flush = fn() => $this->app->make(StaticCache::class)->flush();
        $this->app->terminating($flush);
        // Queue workers don't terminate between jobs, and forget scoped instances before the next one.
        Event::listen([JobProcessed::class, JobExceptionOccurred::class], $flush);

        ClearCaches::add('cloud-static-cache', [
            'label' => 'Craft Cloud static cache',
            'action' => static fn() => dispatch_sync(PurgeStaticCache::everything()),
        ]);

        $this->commands([PurgeCommand::class]);

        // Craft creates the `craft` group when it boots. Its `SetHeaders` middleware applies `{% expires %}` and
        // `noCache()`, so Cloud's middleware has to run outside it. Laravel's priority list then moves it outside the
        // `web` group's cookie and session middleware too, so it sees the cookies the response ends up with.
        $this->app->booted(function (): void {
            $this->app->make(Router::class)->prependMiddlewareToGroup('craft', CacheResponse::class);
            $this->app->make(Kernel::class)->addToMiddlewarePriorityBefore(EncryptCookies::class, CacheResponse::class);
        });
    }
}
