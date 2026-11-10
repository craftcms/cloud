<?php

declare(strict_types=1);

namespace craft\cloud;

use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out a response's `CDN-Cache-Control` header, from, in order:
 *
 * 1. a `CDN-Cache-Control` header the response already has
 * 2. the response's own `Cache-Control` or `Expires` lifetime, with `s-maxage` taking precedence
 * 3. Cloud's defaults, when the response has no lifetime or restriction of its own
 *
 * The lifetime is then capped at the earliest expiry date of the elements the page used.
 */
class CdnCacheControl
{
    private const array RESTRICTIONS = ['no-store', 'no-cache', 'private'];

    public static function for(Response $response, ?int $expiry): string
    {
        $cdnCacheControl = $response->headers->get('CDN-Cache-Control');
        $directives = $cdnCacheControl !== null
            ? new HeaderBag(['Cache-Control' => $cdnCacheControl])
            : self::fromResponse($response);

        if ($directives === null) {
            return 'no-store';
        }

        if ($expiry !== null && !$directives->hasCacheControlDirective('no-store')) {
            $maxAge = $directives->getCacheControlDirective('max-age');
            $sharedMaxAge = $directives->getCacheControlDirective('s-maxage');

            if ($maxAge === null || (int) $maxAge > $expiry) {
                $directives->addCacheControlDirective('max-age', (string) $expiry);
            }

            if ($sharedMaxAge !== null && (int) $sharedMaxAge > $expiry) {
                $directives->addCacheControlDirective('s-maxage', (string) $expiry);
            }
        }

        return (string) $directives->get('Cache-Control');
    }

    private static function fromResponse(Response $response): ?HeaderBag
    {
        // Symfony reports `no-cache, private` for responses without a policy. Adding `public` to a copy leaves only
        // the directives the response set itself.
        $explicit = clone $response->headers;
        $explicit->addCacheControlDirective('public');

        if (array_any(self::RESTRICTIONS, $explicit->hasCacheControlDirective(...))) {
            return null;
        }

        $directives = new HeaderBag(['Cache-Control' => $explicit->get('Cache-Control')]);
        $directives->removeCacheControlDirective('s-maxage');
        $maxAge = $response->getMaxAge();

        if ($maxAge === null) {
            $maxAge = (int) config('cloud.static_cache_duration', default: 31_536_000);

            if (!$directives->hasCacheControlDirective('stale-while-revalidate')) {
                $directives->addCacheControlDirective(
                    'stale-while-revalidate',
                    (string) config('cloud.static_cache_stale_while_revalidate_duration', default: 3600),
                );
            }
        }

        $directives->addCacheControlDirective('max-age', (string) max(0, $maxAge));

        return $directives;
    }
}
