# Craft Cloud Package

Run Craft CMS on Craft Cloud.

## Static cache

On Craft Cloud, site pages are cached by the gateway and CDN. Cloud builds on the `craftcms/http-cache` package and Craft's `ResponseTagger`: it registers a `cloud` HTTP cache client and makes it the default, and sets its `Cache-Tag` formatter.

Craft's global `TagResponse` middleware tags successful GET and HEAD site responses with the elements they query, plus any tags added with `ResponseTagger`, and caps their lifetimes at the earliest element expiry. Cloud's `CacheResponse` middleware runs first, so it sees the final response, and sets `CDN-Cache-Control`:

- a `CDN-Cache-Control` header the page set itself, or
- the page's `s-maxage`, `max-age` or `Expires` lifetime (including `{% expires %}` and `ResponseHeaders::setCache()`), with `stale-while-revalidate` and `stale-if-error`, or
- for pages that set no cache policy of their own, Cloud's default of one year with an hour of `stale-while-revalidate`.

The lifetime is capped at the earliest element expiry. The page's own `Cache-Control` header isn't changed.

Responses that Craft's `CachePolicy` won't let a cache store (`no-store`, `no-cache`, `private`, responses that set cookies) get no `CDN-Cache-Control`. Craft sends no-cache headers for visitors with a session, saved sessions, and token requests.

`Cache-Tag` holds each tag hashed to `{shortEnvironmentId}{crc32}`, limited to Cloudflare's 1,000 tags and 16 KiB, with `{environmentId}:overflow` added when tags are dropped. Purges hash tags the same way.

Element cache invalidations, other than for drafts and revisions, are purged by tag, and saved or deleted elements are purged by URL, through `HttpCache::invalidator('cloud')`. Purges are sent through the signed gateway API once the request, command or queue job finishes. To mark tagged pages stale instead of removing them, set `'clients' => ['cloud' => ['soft_purge' => true]]` in `config/http-cache.php`.

The Caches utility has a “Craft Cloud static cache” option, and these commands purge directly:

- `php craft cloud:static-cache:purge-all`
- `php craft cloud:static-cache:purge-origin`
- `php craft cloud:static-cache:purge-cdn`
- `php craft cloud:static-cache:purge-tags {tags*}`
- `php craft cloud:static-cache:purge-prefixes {prefixes*}`

Cloud reads `CRAFT_CLOUD_ENVIRONMENT_ID` and `CRAFT_CLOUD_SIGNING_KEY`. `config/cloud.php` can set `environment_id`, `signing_key`, `gateway_base_url`, `static_cache_duration` and `static_cache_stale_while_revalidate_duration`.
