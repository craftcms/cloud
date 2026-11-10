<?php

declare(strict_types=1);

namespace craft\cloud\Http\Middleware;

use Closure;
use craft\cloud\CacheTags;
use craft\cloud\CdnCacheControl;
use CraftCms\Cms\Element\ElementCaches;
use CraftCms\Cms\Http\Middleware\HandleTokenRequest;
use CraftCms\Cms\Http\Middleware\ResolveSite;
use CraftCms\Cms\Route\MatchedElement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells Craft Cloud's CDN how long it may cache a site page, with `CDN-Cache-Control`, and which element cache tags
 * purge it, with `Cache-Tag`.
 *
 * This runs outside Craft's `SetHeaders` middleware and Laravel's session and cookie middleware, so it sees the
 * response's final `Cache-Control` header and cookies. The browser's `Cache-Control` header is left alone. Cloud
 * always sends `CDN-Cache-Control`, as an ESI `Surrogate-Control` header makes Cloudflare ignore `Cache-Control`.
 */
class CacheResponse
{
    public function __construct(
        private readonly ElementCaches $elementCaches,
        private readonly CacheTags $tags,
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

        if (!$response->isOk() || $response->getContent() === false) {
            return $response;
        }

        $response->headers->set('CDN-Cache-Control', CdnCacheControl::for($response, $expiry));
        $header = $this->tags->header([
            ...array_map($this->tags->dependency(...), $dependency->tags ?? []),
            ...explode(',', (string) $response->headers->get('Cache-Tag')),
        ]);
        $response->headers->remove('Cache-Tag');

        if ($header !== '') {
            $response->headers->set('Cache-Tag', $header);
        }

        return $response;
    }

    /**
     * Whether the response may differ per visitor, or comes from a link that isn't public.
     *
     * Site routes start their session lazily, so the session and `XSRF-TOKEN` cookies are only sent when the page
     * used the session. Core only marks preview token responses as uncacheable. Other token routes, and site tokens,
     * which can serve disabled sites, are excluded here.
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
