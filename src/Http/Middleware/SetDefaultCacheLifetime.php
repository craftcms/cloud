<?php

declare(strict_types=1);

namespace craft\cloud\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives site pages that set no cache policy of their own Cloud's default: `public`, a year of `s-maxage`, and an hour
 * of `stale-while-revalidate`.
 *
 * Global middleware that runs inside Craft's `TagResponse`, so the default is capped at the earliest element expiry
 * like any other lifetime. {@see CacheResponse} copies it to `CDN-Cache-Control` and then restores the page's own
 * `Cache-Control`, so browsers and other caches don't see it.
 */
class SetDefaultCacheLifetime
{
    private const array RESTRICTIONS = ['no-store', 'no-cache', 'private'];

    /**
     * The `Cache-Control` header the current response had before it got the default, if it did. The middleware is
     * scoped to the request.
     */
    public ?string $originalCacheControl = null;

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (!$response instanceof Response || !$this->isEligible($request, $response)) {
            return $response;
        }

        $this->originalCacheControl = (string) $response->headers->get('Cache-Control');
        $response->setPublic();
        $response->setSharedMaxAge((int) config('cloud.static_cache_duration', default: 31_536_000));

        if (!$response->headers->hasCacheControlDirective('stale-while-revalidate')) {
            $response->setStaleWhileRevalidate((int) config(
                'cloud.static_cache_stale_while_revalidate_duration',
                default: 3600,
            ));
        }

        return $response;
    }

    private function isEligible(Request $request, Response $response): bool
    {
        // Responses to `Authorization` requests may only be stored when they say so themselves (RFC 9111 §3.5).
        if (
            !$request->isMethodCacheable()
            || $request->isCpRequest()
            || $request->isActionRequest()
            || $request->headers->has('Authorization')
            || !$response->isOk()
            || $response->getMaxAge() !== null
        ) {
            return false;
        }

        // Symfony reports `no-cache, private` for responses without a policy. Adding `public` to a copy leaves only the
        // directives the response set itself.
        $explicit = clone $response->headers;
        $explicit->addCacheControlDirective('public');

        return !array_any(self::RESTRICTIONS, $explicit->hasCacheControlDirective(...));
    }
}
