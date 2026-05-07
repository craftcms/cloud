# Craft Cloud

Craft Cloud runtime integration for Craft CMS 6.

This package is intentionally small while the Craft 6 integration is rebuilt. It currently provides:

- Bref Lambda handlers under the `craft\cloud\bref` namespace.
- A Laravel service provider that configures Craft Cloud queue and cache defaults when running in Cloud/Lambda.

## Runtime Configuration

When `CRAFT_CLOUD` or `AWS_LAMBDA_RUNTIME_API` is present, `craft\cloud\CloudServiceProvider` configures:

- `queue.default` as an SQS connection using `CRAFT_CLOUD_SQS_URL`.
- `cache.default` as a Laravel `failover` store.

The cache store uses the Cloud Redis/Valkey endpoint when `CRAFT_CLOUD_CACHE_SRV` or `CRAFT_CLOUD_REDIS_URL` is available, then falls back to Laravel’s database and array stores.
