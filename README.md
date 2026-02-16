# Craft Cloud Monorepo

This monorepo contains packages for running Craft CMS on [Craft Cloud](https://craftcms.com/cloud).

## Packages

### [`craftcms/cloud`](packages/cloud)

Craft CMS plugin providing user-facing Cloud features: filesystem types, template helpers, image transforms, and static caching.

```bash
composer require craftcms/cloud
```

### [`craftcms/cloud-ops`](packages/cloud-ops)

Yii2 extension for Cloud infrastructure. Configures cache, queue, session, and other runtime components. Automatically installed as a dependency of `craftcms/cloud`.

Uses [`codezero/composer-preload-files`](https://github.com/codezero-be/composer-preload-files) to ensure the `cloud-ops` autoload file (which defines `craft_modify_app_config()`) is loaded before any other package that might define the same function—including older versions of `craftcms/cloud`. This allows projects to safely migrate from the legacy single-package setup to the new split architecture without bootstrap conflicts.

## Releasing

Packages are split to their own repositories via GitHub Actions on push to `main` or on tag.

**To release a new version:**

```bash
git tag cloud-ops/1.0.0   # releases craftcms/cloud-ops 1.0.0
git tag cloud/3.0.0       # releases craftcms/cloud 3.0.0
git push origin <tag>
```

The workflow extracts the version from the tag prefix and pushes it to the target repo. Packagist picks up new tags automatically.

**Version strategy:**

| Package              | Version | `craftcms/cms`   | `craftcms/cloud-ops` |
| -------------------- | ------- | ---------------- | -------------------- |
| `craftcms/cloud`     | 1.x     | `^4.6`           | —                    |
| `craftcms/cloud`     | 2.x     | `^5.0`           | —                    |
| `craftcms/cloud`     | 3.x     | `^4.6 \|\| ^5.0` | `^1.0`               |
| `craftcms/cloud`     | 4.x     | `^6.0`           | TBD                  |
