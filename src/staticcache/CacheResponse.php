<?php

declare(strict_types=1);

namespace craft\cloud\staticcache;

use Closure;
use CraftCms\Cms\Element\ElementCaches;
use CraftCms\Cms\Http\Middleware\HandleTokenRequest;
use CraftCms\Cms\Http\Middleware\ResolveSite;
use CraftCms\Cms\Route\MatchedElement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets `CDN-Cache-Control` and `Cache-Tag` on site responses.
 *
 * This runs outside Craft's `SetHeaders` middleware and Laravel's session and cookie middleware, so it sees the
 * response's final `Cache-Control` header and cookies. The browser's `Cache-Control` header is left alone.
 */
class CacheResponse
{
    public function __construct(
        private readonly ElementCaches $elementCaches,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (!$request->isMethodCacheable() || $request->isCpRequest()) {
            return $next($request);
        }

        $this->elementCaches->startCollectingCacheInfo();

        try {
            $response = $next($request);
            $element = MatchedElement::get();

            if ($element) {
                $this->elementCaches->collectCacheInfoForElement($element);
            }
        } finally {
            [$dependency, $expiry] = $this->elementCaches->stopCollectingCacheInfo();
        }

        if (!$response instanceof Response) {
            return $response;
        }

        if ($this->isPersonal($request, $response)) {
            $response->headers->set('CDN-Cache-Control', 'no-store');

            return $response;
        }

        if ($response->isOk() && $response->getContent() !== false) {
            if (!$response->headers->has('CDN-Cache-Control')) {
                $response->headers->set('CDN-Cache-Control', CdnCacheControl::for($response, $expiry));
            }

            if ($dependency !== null) {
                $response->headers->set('Cache-Tag', StaticCache::header($dependency->tags));
            }
        }

        return $response;
    }

    /**
     * Whether the response may differ per visitor, or comes from a link that isn't public.
     *
     * Site routes start their session lazily, so the session and `XSRF-TOKEN` cookies are only sent when the page
     * used the session. Core only marks preview token responses as uncacheable, so other token routes, and site
     * tokens, which can serve disabled sites, are excluded here.
     */
    private function isPersonal(Request $request, Response $response): bool
    {
        return (
            $request->cookies->has((string) config('session.cookie'))
            || $request->headers->has('Authorization')
            || $response->headers->getCookies() !== []
            || Context::getHidden(HandleTokenRequest::HAD_TOKEN_KEY) === true
            || Context::getHidden(ResolveSite::HAD_SITE_TOKEN_KEY) === true
        );
    }
}
