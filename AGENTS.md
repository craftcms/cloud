# craftcms/cloud

Laravel package that runs Craft CMS 6 on Craft Cloud. Namespace `CraftCms\Cloud`, PHP 8.5.

## Commands

- `composer test`: Pest, with Orchestra Testbench booting Craft's and Cloud's providers (`tests/TestCase.php`).
- `composer lint` / `composer fix`: Mago. CI runs `composer validate --strict`, lint, and tests.
- `composer serve`: builds and serves the workbench on `127.0.0.1:8000`.

## Workbench

`testbench.yaml` plus `workbench/` make a Craft 6 site with this package installed, on SQLite, in Testbench's skeleton under `vendor/orchestra/testbench-core/laravel`. `composer build` creates the database and runs `craft:install` (admin / password); it's a no-op once Craft is installed.

In an orb, run `amp orb services ensure` to start it behind a portal. `composer install` and `composer dump-autoload` purge the skeleton, including the database, so run `amp orb service restart workbench` afterwards.

Craft Cloud is detected from `CRAFT_CLOUD` or `AWS_LAMBDA_RUNTIME_API`, so the workbench runs the off-Cloud code paths.

## Constraints

- `src/bref/handlers/*.php` are Lambda handler paths referenced by Cloud's infrastructure. Don't move or rename them.
- Read `CRAFT_CLOUD_*` variables through `CraftCms\Cloud\Cloud::env()`.
