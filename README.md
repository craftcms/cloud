# Craft Cloud Package

Run Craft CMS on Craft Cloud.

## Static cache

On Craft Cloud, site pages are cached by the gateway and CDN. Cloud builds on Craft's HTTP cache layer (`CraftCms\Cms\HttpCache`): it registers a `cloud` HTTP cache client and makes it the default, and binds its own tag encoder and `Cache-Tag` formatter.

Craft's global `TagResponse` middleware tags successful GET and HEAD site responses with the elements they query, plus any tags added with `ResponseTagger`, and caps their lifetimes at the earliest element expiry. Around it, Cloud:

- Gives pages that set no cache policy of their own a default of `public, s-maxage` of one year with an hour of `stale-while-revalidate`, from middleware that runs inside `TagResponse`, so the default is capped too.
- Sets `CDN-Cache-Control` from the final response, outside `TagResponse`: a `CDN-Cache-Control` header the page set, or its `s-maxage`, `max-age` or `Expires` lifetime (including `{% expires %}` and `ResponseHeaders::setCache()`), with `stale-while-revalidate` and `stale-if-error`. The page's own `Cache-Control` header is restored after Cloud's default is copied, so browsers don't see it.
- Sends no `CDN-Cache-Control` for responses that Craft's `CachePolicy` won't let a cache store (`no-store`, `private`, responses that set cookies) or that say `no-cache`. Craft sends no-cache headers for visitors with a session, saved sessions, and token requests.

`Cache-Tag` holds each tag hashed to `{shortEnvironmentId}{crc32}`, limited to Cloudflare's 1,000 tags and 16 KiB, with `{environmentId}:overflow` added when tags are dropped. Purges hash tags the same way.

Element cache invalidations, other than for drafts and revisions, are purged by tag, and saved or deleted elements are purged by URL, through `HttpCache::invalidator('cloud')`. Purges are sent through the signed gateway API once the request, command or queue job finishes. Set `soft_purge` on the `cloud` client in `config/craft/http-cache.php` to mark tagged pages stale instead of removing them.

The Caches utility has a “Craft Cloud static cache” option, and these commands purge directly:

- `php craft cloud:static-cache:purge-all`
- `php craft cloud:static-cache:purge-origin`
- `php craft cloud:static-cache:purge-cdn`
- `php craft cloud:static-cache:purge-tags {tags*}`
- `php craft cloud:static-cache:purge-prefixes {prefixes*}`

Cloud reads `CRAFT_CLOUD_ENVIRONMENT_ID` and `CRAFT_CLOUD_SIGNING_KEY`. `config/cloud.php` can set `environment_id`, `signing_key`, `gateway_base_url`, `static_cache_duration` and `static_cache_stale_while_revalidate_duration`.
