<?php

declare(strict_types=1);

namespace craft\cloud\Http\Middleware;

use Closure;
use CraftCms\Cms\HttpCache\CachePolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells Craft Cloud's CDN how long it may cache a response, with `CDN-Cache-Control`.
 *
 * Global middleware that runs first, outside Craft's `TagResponse`, so it sees the final response: its lifetimes
 * capped at the earliest element expiry, and the cookies it ends up with. The lifetime comes from `s-maxage`, or else
 * `max-age` or `Expires`, including Cloud's default from {@see SetDefaultCacheLifetime}. `stale-while-revalidate` and
 * `stale-if-error` are copied too. A `CDN-Cache-Control` header the response set itself is kept.
 *
 * Only successful responses get one. Responses that Craft's {@see CachePolicy} won't let a cache store, such as
 * `no-store` or `private` responses and responses that set cookies, and `no-cache` responses get no
 * `CDN-Cache-Control` header, even one they set themselves.
 */
class CacheResponse
{
    private const array DIRECTIVES = ['stale-while-revalidate', 'stale-if-error'];

    public function __construct(
        private readonly CachePolicy $policy,
        private readonly SetDefaultCacheLifetime $defaults,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (!$response instanceof Response) {
            return $response;
        }

        if (!$this->isCacheable($request, $response)) {
            $response->headers->remove('CDN-Cache-Control');
        } elseif (
            $response->isSuccessful()
            && !$response->headers->has('CDN-Cache-Control')
            && $response->getMaxAge() !== null
        ) {
            $response->headers->set('CDN-Cache-Control', $this->cdnCacheControl($response));
        }

        if ($this->defaults->originalCacheControl !== null) {
            $response->headers->set('Cache-Control', $this->defaults->originalCacheControl);
        }

        return $response;
    }

    /**
     * Symfony's `Response::isCacheable()`, which the default policy uses, lets `no-cache` responses with a lifetime
     * be stored. They mustn't be served without revalidating, which Cloud's CDN doesn't do, so it doesn't cache them.
     */
    private function isCacheable(Request $request, Response $response): bool
    {
        return (
            $this->policy->isResponseCacheable($request, $response)
            && !$response->headers->hasCacheControlDirective('no-cache')
        );
    }

    private function cdnCacheControl(Response $response): string
    {
        $directives = new HeaderBag();
        $directives->addCacheControlDirective('public');
        $directives->addCacheControlDirective('max-age', (string) max(0, (int) $response->getMaxAge()));

        foreach (self::DIRECTIVES as $directive) {
            $value = $response->headers->getCacheControlDirective($directive);

            if ($value !== null) {
                $directives->addCacheControlDirective($directive, (string) $value);
            }
        }

        return (string) $directives->get('Cache-Control');
    }
}
