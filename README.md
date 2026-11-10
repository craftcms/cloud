# Craft Cloud

Runs Craft CMS 6 on [Craft Cloud](https://craftcms.com/cloud).

```bash
composer require craftcms/cloud
```

Laravel discovers `CraftCms\Cloud\CloudServiceProvider` automatically. Outside Craft Cloud it changes nothing, so the same project runs locally as usual.

## What it configures on Craft Cloud

Craft Cloud is detected from `CRAFT_CLOUD` or `AWS_LAMBDA_RUNTIME_API`. When either is set, the provider:

- **Queue:** uses the environment's SQS queue (`CRAFT_CLOUD_SQS_URL`) as the default connection, dispatching after commit.
- **Cache:** uses a failover store: Valkey (found with the `CRAFT_CLOUD_CACHE_SRV` SRV record, with 1-second timeouts), then the database, then memory.
- **Logging:** sends the default channel to stderr, which is all Lambda collects.
- **Sessions:** replaces `file` or `cookie` sessions with `database` sessions. Other drivers are left alone.

## Lambda handlers

The Cloud platform runs these files as Lambda handlers, so their paths must not change:

- `src/bref/handlers/job-sqs-cmd.php` runs queued jobs in-process with bref/laravel-bridge.
- `src/bref/handlers/command-sqs-cmd.php` runs Craft console commands sent by the platform and posts their output back.

## Development

```bash
composer install
composer test   # Pest, with Orchestra Testbench booting Craft
composer lint   # Mago
composer fix
```

Releases are tagged by hand with [release-it](https://github.com/release-it/release-it), which drafts a GitHub release:

```bash
npx release-it 5.0.0-beta.1
```
