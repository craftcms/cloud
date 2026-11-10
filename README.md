# Craft Cloud Package

Run Craft CMS on Craft Cloud.

## Static cache

On Craft Cloud, site pages are cached by the gateway and CDN. Cloud's `CacheResponse` middleware runs first in Craft's `craft` middleware group, outside Craft's `SetHeaders` and Laravel's cookie and session middleware, so it sees the final response.

For successful GET and HEAD site requests, it collects element cache tags and expiry with `ElementCaches`, including the matched element, and sets:

- `CDN-Cache-Control`: an existing `CDN-Cache-Control` header, or the response's own lifetime (`s-maxage` first, then `max-age` or `Expires`, including `{% expires %}` and `ResponseHeaders::setCache()`), or Cloud's default of one year with an hour of `stale-while-revalidate`. The lifetime is capped at the earliest element expiry. `private`, `no-cache` and `no-store` responses get `no-store`. The browser's `Cache-Control` header isn't changed.
- `Cache-Tag`: the collected tags, hashed with the short environment ID, plus any `Cache-Tag` the response set. It's limited to Cloudflare's 1,000 tags, 16 KiB, and 1,024 bytes per tag, with `{environmentId}:overflow` added when tags are dropped.

Responses get `CDN-Cache-Control: no-store` when the request has a session cookie or `Authorization` header, the response sets a cookie, or the request used a route token or site token.

Element cache invalidations, other than for drafts and revisions, are purged by tag, and saved or deleted elements are purged by URI. Purges are sent through the signed gateway API once the request, command or queue job finishes.

The Caches utility has a “Craft Cloud static cache” option, and these commands purge directly:

- `php craft cloud:static-cache:purge-all`
- `php craft cloud:static-cache:purge-origin`
- `php craft cloud:static-cache:purge-cdn`
- `php craft cloud:static-cache:purge-tags {tags*}`
- `php craft cloud:static-cache:purge-prefixes {prefixes*}`

Cloud reads `CRAFT_CLOUD_ENVIRONMENT_ID` and `CRAFT_CLOUD_SIGNING_KEY`. `config/cloud.php` can set `environment_id`, `signing_key`, `gateway_base_url`, `static_cache_duration` and `static_cache_stale_while_revalidate_duration`.
