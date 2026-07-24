<a href="https://craftcms.com/cloud" rel="noopener" target="_blank" title="Craft Cloud"><img src="https://raw.githubusercontent.com/craftcms/.github/v3/profile/product-icons/craft-cloud.svg" alt="Craft Cloud icon" width="65"></a>

# Craft Cloud Extension

Welcome to [**Craft Cloud**](https://craftcms.com/cloud)!

This repository contains source code for the `craftcms/cloud` Composer package, which is required to run a Craft project on our first-party hosting platform, Craft Cloud.

When installed, the extension automatically [bootstraps](https://www.yiiframework.com/doc/guide/2.0/en/runtime-bootstrapping) itself and makes necessary [application configuration](https://craftcms.com/docs/5.x/reference/config/app.html) changes for the detected environment:

- :cloud_with_lightning: **Cloud:** There’s no infrastructure settings to worry about—database, queue, cache, and session configuration is handled for you.
- :computer: **Local development:** Craft runs normally, in your favorite [development environment](https://craftcms.com/docs/5.x/install.html).

:sparkles: To learn more about Cloud, check out [our website](https://craftcms.com/cloud)—or dive right in with [Craft Console](https://console.craftcms.com/cloud). Interested in everything the extension does to get your app ready for Cloud? Read our [Cloud extension deep-dive](https://craftcms.com/knowledge-base/cloud-extension), in the knowledge base.

## Installation

The Cloud extension can be installed in any existing Craft 4.6+ project by running `php craft setup/cloud`. Craft will add the `craftcms/cloud` package and run the extension’s own setup wizard.

> [!TIP]
> This process includes the creation of a [`craft-cloud.yaml` configuration file](https://craftcms.com/knowledge-base/cloud-config) which helps Cloud understand your project’s structure and determines which versions of PHP and Node your project will use during builds and at runtime.

When you [deploy](https://craftcms.com/knowledge-base/cloud-deployment) a project to Cloud, the `cloud/up` command will run, wrapping Craft’s built-in [`up` command](https://craftcms.com/docs/5.x/reference/cli.html#up) and adding the cache and session tables (if they’re not already present).

## Filesystem

When setting up your project’s assets, use the provided **Craft Cloud** filesystem type. Read more about [managing assets in Cloud projects](https://craftcms.com/knowledge-base/cloud-assets).

## Testing

The Codeception `unit` suite on `3.x` boots Craft and expects a local test database.

```bash
composer test:init
composer test:up
composer test
composer test:down
```

`composer test:init` will create `tests/.env` from `tests/.env.example` if it does not already exist. `composer test:up` uses that file when starting the MySQL service defined in `tests/docker-compose.yaml`.

For local compatibility work on `3.x`, it can be helpful to keep your main checkout on the default/latest Craft 5 dependency set and use a separate Git worktree for Craft 4 so each checkout can keep its own `vendor/`, `composer.lock`, and `tests/.env` state.

```bash
git worktree add ../cloud-3x-craft4 3.x

# In the Craft 4 worktree:
composer update "craftcms/cms:^4.6" "craftcms/flysystem:^1.0" --with-all-dependencies --no-audit

# In your main checkout:
composer update "craftcms/cms:^5" "craftcms/flysystem:^2.0" --with-all-dependencies
```

## Developer Features

### Signed HTTP Requests

Use the module’s request signer to sign a PSR-7 request with Cloud’s signing key before sending it to any destination that can verify HTTP message signatures.

```php
use craft\cloud\Module;
use GuzzleHttp\Psr7\Request;

$signer = Module::getInstance()->getRequestSigner();

$signedRequest = $signer->sign(new Request('POST', 'https://example.test/webhook'));
```

External systems can create compatible signatures without this PHP package. See
[httpsig.org](https://httpsig.org/) for more information about HTTP message signatures. For example,
install [`http-message-sig`](https://www.npmjs.com/package/http-message-sig) in a Node-based build
environment:

```bash
npm install http-message-sig
```

Then a build script, e.g. on Vercel, can sign a Craft GraphQL request:

```js
import crypto from 'node:crypto';
import { signatureHeadersSync } from 'http-message-sig';

const method = 'POST';
const url = process.env.CRAFT_GRAPHQL_URL;

const body = JSON.stringify({
    query: `
        {
            entries(section: "blog") {
                title
                url
            }
        }
    `,
});

const headers = {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${process.env.CRAFT_GRAPHQL_TOKEN}`,
};

const signer = {
    keyid: 'hmac',
    alg: 'hmac-sha256',
    signSync(data) {
        return crypto
            .createHmac('sha256', process.env.CRAFT_CLOUD_SIGNING_KEY)
            .update(data)
            .digest();
    },
};

const created = new Date();
const signatureHeaders = signatureHeadersSync(
    { method, url, headers, body },
    {
        key: 'sig',
        signer,
        components: ['@method', '@target-uri'],
        created,
        expires: new Date(created.getTime() + 300_000),
    },
);

const response = await fetch(url, {
    method,
    headers: {
        ...headers,
        ...signatureHeaders,
    },
    body,
});

const responseBody = await response.json();
```

The `@target-uri` value must be the exact URL being requested, including any query string.

### Signed URLs

Use the module’s URL signer when you need a signed URL instead of a signed HTTP request.

```php
use craft\cloud\Module;

$signer = Module::getInstance()->getUrlSigner();

$signedUrl = $signer->sign('https://example.test/downloads/report.pdf?version=latest');
$isValid = $signer->verify($signedUrl);
```

URL signatures cover the URL’s path and query string, so changing either invalidates the
signature.

### Template Helpers

#### `cloud.artifactUrl()`

Generates a URL to a resource that was uploaded to the CDN during the build and deployment process.

```twig
{# Output a script tag with a build-specific URL: #}
<script src="{{ cloud.artifactUrl('dist/js/app.js') }}"></script>

{# You can also use the extension-provided alias: #}
{% js '@artifactBaseUrl/dist/js/app.js' %}
```

Read more about [how to use artifact URLs](https://craftcms.com/knowledge-base/cloud-builds#artifact-uRLs).

#### `cloud.isCraftCloud`

`true` when the app detects it is running on Cloud infrastructure, `false` otherwise.

```twig
{% if cloud.isCraftCloud %}
  Welcome to Cloud!
{% endif %}
```

### Aliases

The following aliases are available, in addition to [those provided by Craft](https://craftcms.com/docs/5.x/configure.html#aliases).

#### `@web`

On Cloud, the `@web` alias is guaranteed to be the correct environment URL for each HTTP context, whether that be a [preview domain](https://craftcms.com/knowledge-base/cloud-domains#preview-domains) or [custom domain](https://craftcms.com/knowledge-base/cloud-domains#adding-a-domain).

#### `@artifactBaseUrl`

Equivalent to [`cloud.artifactUrl()`](#artifactUrl), this allows [Project Config](https://craftcms.com/docs/5.x/system/project-config.html) settings to take advantage of dynamic, build-specific CDN URLs.

## Configuration

Most configuration (to Craft and the extension itself) is handled directly by Cloud infrastructure, through [environment overrides](https://craftcms.com/docs/5.x/configure.html#environment-overrides). These options are provided strictly for reference, and have limited utility outside the platform.

| Option                | Type           | Description                                                                                                                     |
|-----------------------|----------------|---------------------------------------------------------------------------------------------------------------------------------|
| `artifactBaseUrl`     | `string\|null` | Directly set a fully-qualified URL to build artifacts.                                                                          |
| `s3ClientOptions`     | `array`        | Additional settings to pass to the `Aws\S3\S3Client` instance when accessing storage APIs.                                      |
| `cdnBaseUrl`          | `string`       | Used when building URLs to [assets](#filesystem) and other build [artifacts](#artifacturl).                                     |
| `gatewayBaseUrl`      | `string`       | Used when making gateway API requests.                                                                                          |
| `sqsUrl`              | `string`       | Determines how Craft communicates with the underlying queue provider.                                                           |
| `projectId`           | `string`       | UUID of the current project.                                                                                                    |
| `environmentId`       | `string`       | UUID of the current [environment](https://craftcms.com/knowledge-base/cloud-environments).                                      |
| `buildId`             | `string`       | UUID of the current [build](https://craftcms.com/knowledge-base/cloud-builds).                                                  |
| `accessKey`           | `string`       | AWS access key, used for communicating with storage APIs.                                                                       |
| `accessSecret`        | `string`       | AWS access secret, used in conjunction with the `accessKey`.                                                                    |
| `accessToken`         | `string`       | AWS access token.                                                                                                               |
| `redisUrl`            | `string`       | Connection string for the environment’s Redis instance.                                                                         |
| `signingKey`          | `string`       | A secret value used to protect transform URLs and sign HTTP requests. |
| `useAssetBundleCdn`   | `boolean`      | Whether or not to enable the CDN for asset bundles.                                                                             |
| `previewDomain`       | `string\|null` | Set when accessing an environment from its [preview domain](https://craftcms.com/knowledge-base/cloud-domains#preview-domains). |
| `useQueue`            | `boolean`      | Whether or not to use Cloud’s SQS-backed queue driver.                                                                          |
| `region`              | `string`       | The app region, chosen when creating the project.                                                                               |
| `useAssetCdn`         | `boolean`      | Whether or not to enable the CDN for uploaded assets.                                                                           |
| `useArtifactCdn`      | `boolean`      | Whether or not to enable the CDN for build artifacts and asset bundles.                                                         |
| `staticCacheDuration` | `int`          | The default duration, in seconds, to statically cache requests.                                                                 |

> [!TIP]
> These options can also be set via environment overrides beginning with `CRAFT_CLOUD_`.

### Static cache

Static cache purge events can be configured through Yii’s dependency injection container in `config/app.php`:

```php
use craft\cloud\events\PurgeEvent;
use craft\cloud\StaticCache;

return [
    'container' => [
        'definitions' => [
            StaticCache::class => [
                'class' => StaticCache::class,
                'on ' . StaticCache::EVENT_BEFORE_PURGE => static function(PurgeEvent $event): void {
                    foreach ($event->elements as $element) {
                        if ($element->uri === 'health-check') {
                            $event->isValid = false;
                            break;
                        }
                    }
                },
            ],
        ],
    ],
];
```

The event fires once immediately before each collected batch is sent. Its `elements` property contains the elements associated with the batch.

When a saved element purge proceeds, its non-null site URL is included in tag-based gateway API requests as the optional `fetchUrls` field. URLs are deduplicated, and the gateway asynchronously fetches them after a successful purge to repopulate the cache. Drafts, revisions, deletions, and canceled purges do not send URLs.
