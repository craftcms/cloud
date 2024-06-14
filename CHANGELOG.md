# Release Notes for Craft Cloud  ⛅️

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
