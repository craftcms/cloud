# Release Notes for Craft Cloud  ⛅️

## 2025-03-05

- Released version 1.15.0 of the Cloud Gateway Worker.
  - Added more analytics tracking.

## 2025-03-04

- Released version 1.13.0 of the Cloud Gateway Worker.
  - Removed Vite and replaced it with Wrangler’s default builder `esbuild`.
  - Greatly expands test coverage in the worker.
  - Now collects purge analytics using the [Workers Analytics Engine](https://developers.cloudflare.com/analytics/analytics-engine/).

## 2025-02-20

- Released version 1.12.0 of the Cloud Gateway Worker, which renders unexpected errors more gracefully.

## 2025-02-19

- Released version 1.11.0 of the Cloud Gateway Worker, which fixes a bug where a Cloudflare 522 could be returned instead of a 404 for hostnames not registered with Craft Cloud.

## 2025-02-18

- Released version 1.10.0 of the Cloud Gateway Worker, which adds the ability to purge individual URLs from cache.

## 2025-02-15

- Released version 2.10.1 and 1.66.1 of the [Cloud extension](https://github.com/craftcms/cloud-extension-yii2/releases).

## 2025-02-14

- Reverted to version 1.8.3 of the Cloud Gateway worker because of a [regression](https://status.craftcms.com/incident/513937?mp=true).

## 2025-02-14

- Released version 2.10.0 and 1.66.0 of the [Cloud extension](https://github.com/craftcms/cloud-extension-yii2/releases).
- Released version 1.9.0 of the Cloud Gateway worker, which adds prep work for rate limiting and support for custom static cache keys.

## 2025-01-21

- Added support for PHP 8.4.

## 2024-12-02

- Support for CRON jobs (Scheduled Commands) was added.
- Fix a bug where some Commands could be stuck in a pending state.

## 2024-11-01

- Fix a bug where not all branches would show in BitBucket and GitLab integrations.

## 2024-10-29

- Add node:22 as a valid version for npm builds.

## 2024-10-28

- Added Canada as a region.

## 2024-10-17

- Improved monitoring and alerting for Craft Cloud infrastructure.

## 2024-08-05

- Improved the stability and performance of many parts of Craft Cloud.
- Updated to Bref 2.3.3, which includes PHP 8.2.22 and 8.3.10. 

## 2024-06-17

- Added support for PHP 8.3.

## 2024-06-14

- Bumped the minimum `craftcms/cloud` extension version to ^1.50 or >=2.4.
- Resolved an issue that could prevent deployments from occurring for environments with a lot of variables.

## 2024-05-21

- Added support for Bitbucket and GitLab.
- You can now view how much asset storage each environment is using under your project’s billing page.

## 2024-05-10

- Added a “Repository status” refresh button that checks the health of your Github integration with Craft Cloud.
- You can now create environment variables with no values.

## 2024-05-03

- Additional asset storage can now be purchased on your project’s billing page.

## 2024-04-16

- Fix a bug where the database backup utility may appear when it should not.

## 2024-04-11

- Improved the reliability of backing up large databases via the Console UI.

## 2024-04-03

- Fixed a bug where `artifact-path` could be incorrect with a non-default `app-path`.

## 2024-04-01

- Greatly improved the DNS settings and management UX. 
- MySQL database users now have `CREATE_VIEW` and `SHOW_VIEW` permissions by default.
- You no longer have to deploy on a fresh project before you can back up your database.

## 2024-03-31

- MySQL database backups now pass in the `--single-transaction` flag to help prevent table locking during a backup. 
