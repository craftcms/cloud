# Craft Cloud Package

Run Craft CMS on Craft Cloud.

## Asset storage

The `craft-cloud` disk driver stores files in the project's Craft Cloud bucket, and serves them from Craft Cloud's CDN. Volumes use these disks like any other Laravel disk:

```php
// config/filesystems.php
'disks' => [
    'images' => ['driver' => 'craft-cloud', 'prefix' => 'images'],
],
```

Disks are in the environment's `assets` folder. Set `prefix` to keep a disk's files in a subfolder, and `asset_transformer` to choose its transforms, as on any Laravel disk.

The driver gets the bucket and credentials from the `CRAFT_CLOUD_*` environment variables that Craft Cloud sets. Anywhere else, set `CRAFT_CLOUD_PROJECT_ID`, `CRAFT_CLOUD_ENVIRONMENT_ID` and `CRAFT_CLOUD_REGION`, plus `CRAFT_CLOUD_ACCESS_KEY`/`_SECRET` or other AWS credentials, to use a bucket from your own environment. Set `CRAFT_CLOUD_STORAGE_ENDPOINT` for an S3-compatible service such as MinIO. Without those variables, using a `craft-cloud` disk throws an error.

### Local development

To keep files on your own machine, choose the disk per environment:

```php
'images' => env('CRAFT_CLOUD')
    ? ['driver' => 'craft-cloud', 'prefix' => 'images']
    : ['driver' => 'local', 'root' => public_path('uploads/images'), 'url' => '/uploads/images'],
```

### Upgrading from Craft CMS 5

Craft's upgrade needs a disk for each filesystem handle. For each Craft Cloud filesystem, add a `craft-cloud` disk with the same handle, and its subpath as the `prefix`.
