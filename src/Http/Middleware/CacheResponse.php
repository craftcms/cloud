<?php

declare(strict_types=1);

namespace craft\cloud\Http\Middleware;

use Closure;
use CraftCms\Cms\HttpCache\CachePolicy;
use CraftCms\Cms\HttpCache\ResponseTagger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells Craft Cloud's CDN how long it may cache a response, with `CDN-Cache-Control`.
 *
 * Global middleware that runs first, so it sees the final response: its own lifetimes, already capped at the earliest
 * element expiry by Craft's `TagResponse`, and the cookies it ends up with. The lifetime comes from `s-maxage`, or else
 * `max-age` or `Expires`, with `stale-while-revalidate` and `stale-if-error`. Pages that set no cache policy of their
 * own get Cloud's default of a year, with an hour of `stale-while-revalidate`, capped at the earliest element expiry.
 * The page's `Cache-Control` header isn't changed, and a `CDN-Cache-Control` header it set itself is kept.
 *
 * Only successful responses get one. Responses that Craft's {@see CachePolicy} won't let a cache store, such as
 * `no-store` or `private` responses and responses that set cookies, and `no-cache` responses get no
 * `CDN-Cache-Control` header, even one they set themselves.
 */
class CacheResponse
{
    private const array RESTRICTIONS = ['no-store', 'no-cache', 'private'];
    private const array DIRECTIVES = ['stale-while-revalidate', 'stale-if-error'];

    public function __construct(
        private readonly CachePolicy $policy,
        private readonly ResponseTagger $tagger,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (!$response instanceof Response) {
            return $response;
        }

        $cdnResponse = $this->cdnResponse($request, $response);

        if ($cdnResponse === null || !$this->isCacheable($request, $cdnResponse)) {
            $response->headers->remove('CDN-Cache-Control');
        } elseif ($response->isSuccessful() && !$response->headers->has('CDN-Cache-Control')) {
            $response->headers->set('CDN-Cache-Control', $this->cdnCacheControl($cdnResponse));
        }

        return $response;
    }

    /**
     * The response as Cloud's CDN would store it, or null if it restricts caching itself.
     *
     * That's a copy with its `CDN-Cache-Control` header as `Cache-Control`, if it set one, or with Cloud's default
     * lifetime if it set no cache policy of its own. Otherwise it's the response itself.
     */
    private function cdnResponse(Request $request, Response $response): ?Response
    {
        // Symfony reports `no-cache, private` for responses without a policy. Adding `public` to a copy leaves only the
        // directives the response set itself.
        $explicit = clone $response->headers;
        $explicit->addCacheControlDirective('public');

        if (array_any(self::RESTRICTIONS, $explicit->hasCacheControlDirective(...))) {
            return null;
        }

        $cdnCacheControl = $response->headers->get('CDN-Cache-Control');

        if ($cdnCacheControl !== null) {
            // It's for shared caches, so Symfony's `private` default doesn't apply.
            $directives = new HeaderBag(['Cache-Control' => $cdnCacheControl]);

            if (!$directives->hasCacheControlDirective('private')) {
                $directives->addCacheControlDirective('public');
            }

            $cdnResponse = clone $response;
            $cdnResponse->headers->set('Cache-Control', $directives->get('Cache-Control'));

            return $cdnResponse;
        }

        if ($response->getMaxAge() !== null) {
            return $response;
        }

        // Responses to `Authorization` requests may only be stored when they say so themselves (RFC 9111 §3.5).
        // Control panel and action requests needn't be checked: Craft sends no-cache headers for them.
        if ($request->headers->has('Authorization')) {
            return null;
        }

        $cdnResponse = clone $response;
        $cdnResponse->setPublic();
        $cdnResponse->setSharedMaxAge((int) config('cloud.static_cache_duration', default: 31_536_000));

        if (!$cdnResponse->headers->hasCacheControlDirective('stale-while-revalidate')) {
            $cdnResponse->setStaleWhileRevalidate((int) config(
                'cloud.static_cache_stale_while_revalidate_duration',
                default: 3600,
            ));
        }

        return $cdnResponse;
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
        $maxAge = (int) $response->getMaxAge();
        $expiry = $this->tagger->getExpiryDate();

        if ($expiry !== null) {
            $maxAge = min($maxAge, $expiry->getTimestamp() - now()->getTimestamp());
        }

        $directives = new HeaderBag();
        $directives->addCacheControlDirective('public');
        $directives->addCacheControlDirective('max-age', (string) max(0, $maxAge));

        foreach (self::DIRECTIVES as $directive) {
            $value = $response->headers->getCacheControlDirective($directive);

            if ($value !== null) {
                $directives->addCacheControlDirective($directive, (string) $value);
            }
        }

        return (string) $directives->get('Cache-Control');
    }
}
