# Release Notes for Craft Cloud ⛅️

2025-07-11
Redirect and rewrites are now generally available.
2025-06-23
Released version 1.63.0 of the Cloud API.
Added support for the new backup infrastructure for MySQL.
MySQL backups are now gzip-compressed.
2025-06-13
Released version 1.62.3 of the Cloud API.
Fixed a regression introduced in 1.62.0 where some projects using specific node configurations would not have their artifacts published to the CDN during a build.
2025-06-12
Released version 1.62.1 of the Cloud API.
Craft Console no longer warns you about read-only environment variable changes for pending deployments.
2025-06-12
Released version 1.62.0 of the Cloud API.
All Craft Cloud projects now explicitly have a read-only CRAFT_USE_FILE_LOCKS environment variable set to false, as it is not necessary on serverless/ephemeral environments.
Fixed a bug where artifact publishing during a build did not run for Craft Cloud projects without a node build process.
2025-06-06
Released version 1.61.0 of the Cloud API.
All new Craft Cloud projects get the new builder and commands infrastructure.
2025-05-30
Released version 1.24.0 of the Cloud Gateway Worker.
Improved error handling.
2025-05-28
Released version 1.59.5 of the Cloud API.
Fixed a bug where Cloud subdomains would not get deleted if a domain was deleted.
2025-05-20
Released version 1.57.9 of the Cloud API.
Improved error handling during a build.
Fixed a bug where custom php.ini settings were not being picked up with a custom app-path in craft-cloud.yaml.
2025-05-15
Released version 1.22.0 of the Cloud Gateway Worker.
origin-cf-cache-status and origin-cf-ray headers are returned with origin info in Cloudflare “Orange to Orange” scenarios.
Workers are now deployed with GitHub actions instead of Wrangler.
Increased request timeout at the worker level to 60 seconds.
2025-05-13
Released version 1.56.7 of the Cloud API.
Fixed a bug where deleted subdomains could not be reused in a project.
2025-05-12
Released version 1.20.0 of the Cloud Gateway Worker.
Enable more logging in the worker.
2025-05-08
Released version 2.14.1 and 1.70.1 of the Cloud extension.
Ensure the Cloud transformer is only used with Craft Cloud filesystems.
2025-05-05
Released version 1.19.0 of the Cloud Gateway Worker.
Enforce a 30-second request timeout in the worker.
2025-05-02
Released version 1.18.0 of the Cloud Gateway Worker.
Added more analytics tracking.
2025-04-30
Released version 2.14.0 and 1.70.0 of the Cloud extension.
Adds support for the Cloud Commands and Builds v2 infrastructure.
2025-04-18
Released version 2.13.0 of the Cloud extension.
Fixes a bug where releasing all jobs in the queue in Craft would not delete the jobs in Craft Cloud.
2025-04-15
Released version 1.17.0 of the Cloud Gateway Worker.
Protects against the critical RCE vulnerability that was fixed in Craft 5.6.17 and 4.14.15 for sites that aren’t running those patched versions.
2025-04-01
Released version 2.11.0 and 1.68.0 of the Cloud extension.
Adds configurable log levels.
2025-03-14
Released version 2.12.0 and 1.67.0 of the Cloud extension.
Fixed a bug where replacing an asset would not trigger cache invalidation if they had duplicate file names.
2025-03-05
Released version 1.15.0 of the Cloud Gateway Worker.
Added more analytics tracking.
2025-03-04
Released version 1.13.0 of the Cloud Gateway Worker.
Removed Vite and replaced it with Wrangler’s default builder esbuild.
Greatly expands test coverage in the worker.
Now collects purge analytics using the Workers Analytics Engine.
2025-02-20
Released version 1.12.0 of the Cloud Gateway Worker, which renders unexpected errors more gracefully.
2025-02-19
Released version 1.11.0 of the Cloud Gateway Worker, which fixes a bug where a Cloudflare 522 could be returned instead of a 404 for hostnames not registered with Craft Cloud.
2025-02-18
Released version 1.10.0 of the Cloud Gateway Worker, which adds the ability to purge individual URLs from cache.
2025-02-15
Released version 2.10.1 and 1.66.1 of the Cloud extension.
2025-02-14
Reverted to version 1.8.3 of the Cloud Gateway worker because of a regression.
2025-02-14
Released version 2.10.0 and 1.66.0 of the Cloud extension.
Released version 1.9.0 of the Cloud Gateway worker, which adds prep work for rate limiting and support for custom static cache keys.
2025-01-21
Added support for PHP 8.4.
2024-12-02
Support for CRON jobs (Scheduled Commands) was added.
Fix a bug where some Commands could be stuck in a pending state.
2024-11-01
Fix a bug where not all branches would show in BitBucket and GitLab integrations.
2024-10-29
Add node:22 as a valid version for npm builds.
2024-10-28
Added Canada as a region.
2024-10-17
Improved monitoring and alerting for Craft Cloud infrastructure.
2024-08-05
Improved the stability and performance of many parts of Craft Cloud.
Updated to Bref 2.3.3, which includes PHP 8.2.22 and 8.3.10.
2024-06-17
Added support for PHP 8.3.
2024-06-14
Bumped the minimum craftcms/cloud extension version to ^1.50 or >=2.4.
Resolved an issue that could prevent deployments from occurring for environments with a lot of variables.
2024-05-21
Added support for Bitbucket and GitLab.
You can now view how much asset storage each environment is using under your project’s billing page.
2024-05-10
Added a “Repository status” refresh button that checks the health of your Github integration with Craft Cloud.
You can now create environment variables with no values.
2024-05-03
Additional asset storage can now be purchased on your project’s billing page.
2024-04-16
Fix a bug where the database backup utility may appear when it should not.
2024-04-11
Improved the reliability of backing up large databases via the Console UI.
2024-04-03
Fixed a bug where artifact-path could be incorrect with a non-default app-path.
2024-04-01
Greatly improved the DNS settings and management UX.
MySQL database users now have CREATE_VIEW and SHOW_VIEW permissions by default.
You no longer have to deploy on a fresh project before you can back up your database.
2024-03-31
MySQL database backups now pass in the --single-transaction flag to help prevent table locking during a backup.
