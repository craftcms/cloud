# Craft Cloud Package

Run Craft CMS on Craft Cloud.

## Static cache

On Craft Cloud, the gateway and CDN cache site pages and purge them with Craft's own cache tags.

Cloud's middleware runs outside Craft's `SetHeaders` and Laravel's cookie middleware. For successful GET and HEAD site responses, it sets:

- `CDN-Cache-Control` from the response's `Cache-Control` (`s-maxage`, then `max-age` or `Expires`), so `{% expires %}`, `ResponseHeaders::setCache()` and Symfony's `setSharedMaxAge()` control the CDN. Responses without a lifetime are cached for a year, with an hour of `stale-while-revalidate`. Lifetimes are capped at the earliest expiry of the elements the page used. `private`, `no-cache` and `no-store` responses aren't cached, and an explicit `CDN-Cache-Control` header is left alone.
- `Cache-Tag` with the page's Craft cache tags, including the matched element's.

Responses aren't cached when the request has a session cookie or `Authorization` header, the response sets a cookie, or the request used a route token or site token.

Every `TagDependency::invalidate()` purges the pages with those tags, apart from draft and revision changes. This covers element saves, `ElementCaches::collectCacheTags()` with custom tags, and `craft:invalidate-tags`. Purges are sent once per request, command or queue job, as a queued `PurgeStaticCache` job.

The Caches utility's “Craft Cloud static cache” option (`craft:clear-caches:cloud-static-cache`) purges everything. `php craft cloud:purge` does the same, or purges specific Cloudflare cache tags (`--tag`) or URL prefixes (`--prefix`).
