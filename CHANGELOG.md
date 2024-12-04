# Release Notes for Craft Cloud  ⛅️

## 2024-12-02

- Added support for CRON jobs (Scheduled Commands).
- Fix a bug where some Commands would be stuck as pending.

## 2024-11-1

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
- You no longer have to do a deployment on a fresh project before you can back up your database.

## 2024-03-31

- MySQL database backups now pass in the `--single-transaction` flag to help prevent table locking during a backup. 
