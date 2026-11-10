# Craft Cloud Package

Run Craft CMS on Craft Cloud.

## Response caching

Cloud consumes Craft's `CacheControlApplied` event. It clones the Symfony response policy before translating it, leaves the event snapshot unchanged, and logs the common policy reasons alongside Cloud eligibility decisions.

- `Cache-Control` retains browser `max-age` and shared `s-maxage`. `CDN-Cache-Control` uses the shared lifetime as `max-age`, with the other policy directives preserved. Existing CDN headers cannot bypass core restrictions or dependency-expiry caps.
- Successful GET/HEAD responses without explicit freshness or storage restrictions default to a browser lifetime of zero, a shared lifetime of one year, and one hour of stale-while-revalidate. Defaults run before Craft caps freshness by collected dependency expiry. Explicit lifetimes and private/no-store/no-cache policies do not receive these defaults.
- Incoming session cookies, outgoing cookies, unsafe methods, non-200 responses, streamed responses, and file downloads disable Cloud caching. Other incoming cookies do not by themselves disable it. Core still owns authorization, token, preview, outgoing session-cookie, and CSRF restrictions.
- Dependency tags use the existing short environment ID plus CRC32 encoding. Custom `Cache-Tag` values are normalized and merged. The header is limited to 1,000 tags, 16 KiB total, and 1,024 bytes per tag, with the environment overflow tag when truncated.
- `ElementCachesInvalidated` batches dependency-tag purges until application termination, skips drafts/revisions, and uses the signed Cloud gateway `cache/purge` transport. Purge failures are logged. No direct Cloudflare credentials or new cache store are required.

Cloud registers this behavior automatically on the existing `craft.web` middleware group. There is no host-app middleware setup. Existing ESI `Surrogate-Control` headers remain intact; Cloud still emits `CDN-Cache-Control` so ESI does not bypass the resolved cache policy. This does not add an ESI renderer to 5.x.

Cloud reads `CRAFT_CLOUD_ENVIRONMENT_ID` and `CRAFT_CLOUD_SIGNING_KEY` from the runtime. Optional `config/cloud.php` keys are `environment_id`, `signing_key`, `gateway_base_url`, `static_cache_duration`, and `static_cache_stale_while_revalidate_duration`.

This event is a synchronous middleware checkpoint, not a final-response guarantee. Later listeners and outer middleware can still alter output. Cloud emits headers here; it does not store a response body or redesign Craft's policy.

References: [Symfony HTTP caching](https://symfony.com/doc/current/http_cache.html), [Symfony Response cache methods](https://symfony.com/doc/current/components/http_foundation.html#managing-the-http-cache), and [Cloudflare CDN-Cache-Control precedence](https://developers.cloudflare.com/cache/concepts/cdn-cache-control/).

## CMS dependency during development

This integration depends on [craftcms/cms#20545](https://github.com/craftcms/cms/pull/20545), which is not yet merged. Do not merge/release this change until a compatible CMS version is available and the minimum CMS requirement and lockfile can be updated.

The Composer CMS requirement remains `^6` and existing locked packages are unchanged. The only added dependency is the existing Cloud HTTP-message-signature library and its dependencies for gateway purges. CI checks out the full CMS source at commit [`b439591`](https://github.com/craftcms/cms/commit/b439591fe6c88390aa675e7a1aea5210429d918c) and prepends its namespace during tests. This exercises the actual middleware, event, policy, and dependency collector against Cloud's locked framework dependencies, without copying classes or changing production autoloading. It is not a full installation of the CMS feature branch's dependency tree.

For the same integration checks locally, check out that CMS commit separately and run `CMS_SOURCE_PATH=/absolute/path/to/cms composer test`. Once the dependency is released, remove the CI source override and test bootstrap override, and test against the installed CMS package.
