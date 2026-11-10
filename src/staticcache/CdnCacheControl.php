<?php

declare(strict_types=1);

namespace craft\cloud\staticcache;

use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Derives `CDN-Cache-Control` from a response's `Cache-Control` header, so Craft's and Symfony's cache APIs
 * (`{% expires %}`, `ResponseHeaders::setCache()`, `setSharedMaxAge()`) control the CDN too.
 *
 * Cloudflare reads `CDN-Cache-Control` before `Cache-Control` and ESI's `Surrogate-Control`, so the shared lifetime
 * becomes its `max-age`. Responses without a lifetime of their own are cached for a year, with an hour of
 * `stale-while-revalidate`, as tag purges keep them current. Lifetimes are capped at the earliest element expiry.
 */
class CdnCacheControl
{
    private const int DEFAULT_MAX_AGE = 31_536_000;
    private const int DEFAULT_STALE_WHILE_REVALIDATE = 3600;

    public static function for(Response $response, ?int $expiry): string
    {
        // Symfony reports `no-cache, private` for responses without a policy. Adding `public` to a copy leaves only
        // the directives the response set itself.
        $headers = clone $response->headers;
        $headers->addCacheControlDirective('public');

        if (array_any(['no-store', 'no-cache', 'private'], $headers->hasCacheControlDirective(...))) {
            return 'no-store';
        }

        $directives = new HeaderBag(['Cache-Control' => $headers->get('Cache-Control')]);
        $directives->removeCacheControlDirective('s-maxage');
        $maxAge = $response->getMaxAge();

        if ($maxAge === null) {
            $maxAge = self::DEFAULT_MAX_AGE;

            if (!$directives->hasCacheControlDirective('stale-while-revalidate')) {
                $directives->addCacheControlDirective(
                    'stale-while-revalidate',
                    (string) self::DEFAULT_STALE_WHILE_REVALIDATE,
                );
            }
        }

        $directives->addCacheControlDirective('max-age', (string) min(max(0, $maxAge), $expiry ?? PHP_INT_MAX));

        return (string) $directives->get('Cache-Control');
    }
}
