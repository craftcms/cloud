<?php

declare(strict_types=1);

namespace craft\cloud\Http\Middleware;

use Closure;
use CraftCms\Cms\Http\CacheControl;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheDefaults
{
    public function __construct(
        private readonly CacheControl $cacheControl,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (!$response instanceof Response || !$request->isMethodSafe() || !$response->isOk()) {
            return $response;
        }

        $policy = clone $response;
        $this->cacheControl->applyTo($policy);
        // Discard Symfony's implicit private/no-cache defaults, not explicit restrictions.
        $headers = clone $policy->headers;
        $headers->addCacheControlDirective('public');

        if (
            $policy->getMaxAge() === null
            && $policy->getExpires() === null
            && !$headers->hasCacheControlDirective('private')
            && !$headers->hasCacheControlDirective('no-store')
            && !$headers->hasCacheControlDirective('no-cache')
        ) {
            $response->setSharedMaxAge((int) config('cloud.static_cache_duration', default: 31_536_000));
            $response->setMaxAge(0);

            if (!$headers->hasCacheControlDirective('stale-while-revalidate')) {
                $response->setStaleWhileRevalidate((int) config(
                    'cloud.static_cache_stale_while_revalidate_duration',
                    default: 3600,
                ));
            }
        }

        return $response;
    }
}
