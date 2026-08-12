# Release Notes for Craft Cloud ⛅️

## 2027-08-07
- Released version 1.49.0 of the Cloud Gateway Worker.
  - Improved bot rate limiting logic.

## 2027-07-31
- Released version 1.118.0 of the Cloud API
  - Fixed a soft-delete bug with domains and subdomains.

## 2027-07-28
- Released version 1.118.0 of the Cloud API
  - Added support for [Bref 3](https://bref.sh/news/03-bref-3.0).

## 2027-07-21
- Released version 1.115.0 of the Cloud API
  - Added support for access token reconnects in the GitLab integration.
- Released version 3.9.1 of the `craftcms/cloud` package.
  - Added static cache purge console commands. 

## 2026-07-13
- Released version 1.114.0 of the Cloud API
  - Added support for an Azure source control integration.

## 2026-07-10
- Released version 1.43.0 of the Cloud Gateway Worker.
  - Hardened gateway signature handling.
  - Improved Refine CDN signature and rewrite handling.
  - Added gateway visibility headers.
  - Added support for PDF and SVG raster transforms.

## 2026-06-30
- Released version 3.4.3 of the `craftcms/cloud` package.
  - Released support for [request signing](https://craftcms.com/docs/cloud/request-signing.html).
  - Fixed a bug where uploaded assets might not have their dimensions reported accurately.

## 2026-06-18
- Released version 3.4.1 of the `craftcms/cloud` package.
  - Required version 1.0+ of `craftcms/http-message-signatures`.
- Release version 1.111.0 of the Cloud API.
  - Sensitive environment variables can be revealed in the Console UI, now.

## 2026-05-26
- Released version 1.32.0 of the Cloud Gateway Worker.
  - Add support for [signed requests](https://craftcms.com/docs/cloud/request-signing.html#creating-a-signed-request).

## 2026-05-19
- Added Sweden and the United Kingdom as two new regions.

## 2026-05-15
- Released version 1.100.0 of the Cloud API.
  - The craft-cloud.yaml file is now validated against a [published JSON schema](https://api.craft.cloud/schemas/craft-cloud.schema.json).

## 2026-05-01
- All Craft Cloud projects are now running [Bref 3](https://bref.sh/news/03-bref-3.0).
- Added support for PHP 8.5.
- Removed support for PHP 8.1.
- Released version 1.32.0 of the Cloud Gateway Worker.
  - We now retry AWS origin errors with an exponential backoff.
  - It is easier to track Cloudflare Ray IDs in Orange 2 Orange scenarios.

## 2026-04-23
- Released version 3.1.0 of the `craftcms/cloud` package.
  - Fixed a bug where image transform editing was broken in the control panel for some Craft Cloud projects.

## 2026-04-21
- Released version 1.30.0 of the Cloud Gateway Worker.
  - Fixed a bug where application-level HSTS headers could not be overwritten.

## 2026-04-15
- Released version 1.95.0 of the Cloud API.
  - Fixed a bug where Craft Cloud’s Bitbucket integration was broken for new projects due to API changes on Bitbucket’s end.

## 2026-03-27
- Released version 3.0.4 of the `craftcms/cloud` package.
  - Fixed a Craft 4 compatibility issue where generating asset upload URLs could fail because `craft\models\Volume::getSubpath()` is not available on Craft 4.
  - Fixed the returned upload object key so it matches the presigned upload target path.

## 2026-03-23
- Released version 3.0.0 of the `craftcms/cloud` package.
  - Added support for both Craft 4 and Craft 5.
  - Any [Cloudflare image transform options](https://developers.cloudflare.com/images/transform-images/transform-via-workers/#fetch-options) can now be passed when creating image transforms.

## 2026-03-17
- Released version 1.90.4 of the Cloud API.
  - Fixed a bug where you could (unintentionally) create a project handle with > 25 characters.
  - Fixed a bug where some Sandbox projects could not deploy.

## 2026-03-04
- Released version 1.90.2 of the Cloud API.
  - Increased MySQL’s `sort_buffer_size` value for all existing and new clusters.
  - Added support for Node 24.

## 2026-01-30
- Released version 1.84.5 of the Cloud API.
  - Fixed a bug where environments with thousands of deployments could crash the deployments page.

## 2025-12-11
- Released version 1.27.0 of the Cloud Gateway Worker.
  - Fixed some edge case redirect/rewrite issues.

## 2025-12-05
- Released version 1.26.0 of the Cloud Gateway Worker.
  - Fixed several ESI issues.

## 2025-11-17
- Released version 1.75.0 of the Cloud API.
  - Fixed a bug where scheduled commands might not run on some projects.

## 2025-11-11
- Released version 1.72.0 of the Cloud API.
  - All projects now use the new HTTP Lambda infrastructure.

## 2025-09-23
- Released version 1.68.0 of the Cloud API.
  - Added a new MySQL cluster for Europe.

## 2025-09-11
- Released version 1.66.6 of the Cloud API.
  - Fixed a Postgres backup issue in the APAC region.

## 2025-07-28
- Released version 1.64.6 of the Cloud API.
  - Added Postgres backups to the new backups infrastructure. 

## 2025-07-11
- Redirects and rewrites are now generally available.

## 2025-06-23
- Released version 1.63.0 of the Cloud API.
  - Added support for the new backup infrastructure for MySQL.
  - MySQL backups are now gzip-compressed.

## 2025-06-13
- Released version 1.62.3 of the Cloud API.
  - Fixed a regression introduced in 1.62.0 where some projects using specific node configurations would not have their artifacts published to the CDN during a build.

## 2025-06-12
- Released version 1.62.1 of the Cloud API.
- Craft Console no longer warns you about read-only environment variable changes for pending deployments.

## 2025-06-12
- Released version 1.62.0 of the Cloud API.
  - All Craft Cloud projects now explicitly have a read-only `CRAFT_USE_FILE_LOCKS` environment variable set to `false`, as it is not necessary on serverless/ephemeral environments.
  - Fixed a bug where artifact publishing during a build did not run for Craft Cloud projects without a node build process.

## 2025-06-06
- Released version 1.61.0 of the Cloud API.
  - All new Craft Cloud projects get the new builder and commands infrastructure.

## 2025-05-30
- Released version 1.24.0 of the Cloud Gateway Worker.
  - Improved error handling.

## 2025-05-28
- Released version 1.59.5 of the Cloud API. 
  - Fixed a bug where Cloud subdomains would not get deleted if a domain was deleted.

## 2025-05-20
- Released version 1.57.9 of the Cloud API.
  - Improved error handling during a build.
  - Fixed a bug where custom `php.ini` settings were not being picked up with a custom `app-path` in `craft-cloud.yaml`.

## 2025-05-15
- Released version 1.22.0 of the Cloud Gateway Worker.
  - `origin-cf-cache-status` and `origin-cf-ray` headers are returned with origin info in Cloudflare “Orange to Orange” scenarios.
  - Workers are now deployed with GitHub actions instead of Wrangler.
  - Increased request timeout at the worker level to 60 seconds.

## 2025-05-13
- Released version 1.56.7 of the Cloud API.
  - Fixed a bug where deleted subdomains could not be reused in a project.

## 2025-05-12
- Released version 1.20.0 of the Cloud Gateway Worker.
  - Enable more logging in the worker.

## 2025-05-08
- Released version 2.14.1 and 1.70.1 of the Cloud extension.
  - Ensure the Cloud transformer is only used with Craft Cloud filesystems.

## 2025-05-05
- Released version 1.19.0 of the Cloud Gateway Worker.
  - Enforce a 30-second request timeout in the worker.

## 2025-05-02
- Released version 1.18.0 of the Cloud Gateway Worker.
  - Added more analytics tracking.

## 2025-04-30
- Released version 2.14.0 and 1.70.0 of the Cloud extension.
  - Adds support for the Cloud Commands and Builds v2 infrastructure.

## 2025-04-18
- Released version 2.13.0 of the Cloud extension.
  - Fixes a bug where releasing all jobs in the queue in Craft would not delete the jobs in Craft Cloud.

## 2025-04-15
- Released version 1.17.0 of the Cloud Gateway Worker.
  - Protects against the critical RCE vulnerability fixed in Craft 5.6.17 and 4.14.15 for sites that aren’t running those patched versions.

## 2025-04-01
- Released version 2.11.0 and 1.68.0 of the Cloud extension.
  - Adds configurable log levels.

## 2025-03-14
- Released version 2.12.0 and 1.67.0 of the Cloud extension.
  - Fixed a bug where replacing an asset would not trigger cache invalidation if they had duplicate file names.

## 2025-03-05
- Released version 1.15.0 of the Cloud Gateway Worker.
  - Added more analytics tracking.

## 2025-03-04
- Released version 1.13.0 of the Cloud Gateway Worker.
  - Removed Vite and replaced it with Wrangler’s default builder esbuild.
  - Greatly expands test coverage in the worker.
  - Now collects purge analytics using the Workers Analytics Engine.

## 2025-02-20
- Released version 1.12.0 of the Cloud Gateway Worker, which renders unexpected errors more gracefully.

## 2025-02-19
- Released version 1.11.0 of the Cloud Gateway Worker, which fixes a bug where a Cloudflare 522 could be returned instead of a 404 for hostnames not registered with Craft Cloud.

## 2025-02-18
- Released version 1.10.0 of the Cloud Gateway Worker, which adds the ability to purge individual URLs from cache.

## 2025-02-15
- Released version 2.10.1 and 1.66.1 of the Cloud extension.

## 2025-02-14
- Reverted to version 1.8.3 of the Cloud Gateway worker because of a regression.

## 2025-02-14
- Released version 2.10.0 and 1.66.0 of the Cloud extension.
- Released version 1.9.0 of the Cloud Gateway worker, which adds prep work for rate limiting and support for custom static cache keys.

## 2025-01-21
- Added support for PHP 8.4.

## 2024-12-02
- Support for CRON jobs (Scheduled Commands) was added.
- Fix a bug where some Commands could be stuck in a pending state.

## 2024-11-01
- Fix a bug where not all branches would show in BitBucket and GitLab integrations.

## 2024-10-29
- Add `node:22` as a valid version for npm builds.

## 2024-10-28
- Added Canada as a region.

## 2024-10-17
- Improved monitoring and alerting for Craft Cloud infrastructure.

## 2024-08-05
- Improved the stability and performance across many parts of Craft Cloud.
- Updated to Bref 2.3.3, which includes PHP 8.2.22 and 8.3.10.

## 2024-06-17
- Added support for PHP 8.3.

## 2024-06-14
- Bumped the minimum `craftcms/cloud` extension version to `^1.50` or `>=2.4`.
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
