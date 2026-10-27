<?php

declare(strict_types=1);

use craft\cloud\tests\Support\DnsRecords;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Filesystem\Events\UploadSessionStarting;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

it('does not configure cloud services outside Craft Cloud', function (): void {
    $this->registerProvider();

    expect($this->config->get('queue.default'))
        ->toBe('sync')
        ->and($this->config->get('cache.default'))
        ->toBe('array')
        ->and($this->config->get('logging.default'))
        ->toBe('stack')
        ->and($this->config->get('logging.channels.emergency.path'))
        ->toBe('/var/task/storage/logs/laravel.log')
        ->and($this->config->get('queue.connections.craft-cloud-sqs'))
        ->toBeNull()
        ->and($this->config->get('cache.stores.craft-cloud-failover'))
        ->toBeNull();
});

it('configures Laravel logging to use stderr on Craft Cloud', function (): void {
    $this->server('CRAFT_CLOUD', '1');

    $this->registerProvider();

    expect($this->config->get('logging.default'))
        ->toBe('stderr')
        ->and($this->config->get('logging.channels.emergency.path'))
        ->toBe('php://stderr');
});

it('configures the Craft Cloud SQS queue connection', function (): void {
    $this->server('CRAFT_CLOUD', '1');
    $this->server('CRAFT_CLOUD_SQS_URL', 'https://sqs.us-east-1.amazonaws.com/123456789012/craft-cloud');

    $this->registerProvider();

    expect($this->config->get('queue.default'))
        ->toBe('craft-cloud-sqs')
        ->and($this->config->get('queue.connections.craft-cloud-sqs'))
        ->toBe([
            'driver' => 'sqs',
            'prefix' => '',
            'queue' => 'https://sqs.us-east-1.amazonaws.com/123456789012/craft-cloud',
            'suffix' => '',
            'after_commit' => true,
        ]);
});

it('configures the fallback cache without Redis when no cache SRV record is available', function (): void {
    $this->server('CRAFT_CLOUD', '1');

    $this->registerProvider();

    expect($this->config->get('cache.default'))
        ->toBe('craft-cloud-failover')
        ->and($this->config->get('cache.stores.craft-cloud-failover'))
        ->toBe([
            'driver' => 'failover',
            'stores' => ['database', 'array'],
        ])
        ->and($this->config->get('cache.stores.craft-cloud-redis'))
        ->toBeNull();
});

it('prepends Redis to the fallback cache when the cache SRV record is available', function (): void {
    $this->server('CRAFT_CLOUD', '1');
    $this->server('CRAFT_CLOUD_CACHE_SRV', '_cache._tcp.example.test');

    DnsRecords::fake([
        [
            'target' => 'redis.internal',
            'port' => 6379,
        ],
    ]);

    $this->registerProvider();

    expect($this->config->get('database.redis.craft-cloud'))
        ->toBe([
            'url' => 'redis://redis.internal:6379',
            'database' => 0,
        ])
        ->and($this->config->get('cache.stores.craft-cloud-redis'))
        ->toBe([
            'driver' => 'redis',
            'connection' => 'craft-cloud',
            'lock_connection' => 'craft-cloud',
        ])
        ->and($this->config->get('cache.stores.craft-cloud-failover'))
        ->toBe([
            'driver' => 'failover',
            'stores' => ['craft-cloud-redis', 'database', 'array'],
        ]);
});

describe('filesystems', function (): void {
    beforeEach(function (): void {
        $this->server('CRAFT_CLOUD', '1');
        $this->server('CRAFT_CLOUD_PROJECT_ID', 'project-bucket');
        $this->server('CRAFT_CLOUD_ENVIRONMENT_ID', 'environment-id');
        $this->server('CRAFT_CLOUD_REGION', 'us-west-2');
    });

    afterEach(function (): void {
        Container::setInstance(null);
    });

    it('registers the project bucket as disks for assets and staged uploads', function (): void {
        [$key, $secret, $token] = [bin2hex(random_bytes(8)), bin2hex(random_bytes(8)), bin2hex(random_bytes(8))];
        $this->server('CRAFT_CLOUD_ACCESS_KEY', $key);
        $this->server('CRAFT_CLOUD_ACCESS_SECRET', $secret);
        $this->server('CRAFT_CLOUD_ACCESS_TOKEN', $token);

        $this->registerProvider();

        $disk = [
            'driver' => 's3',
            'bucket' => 'project-bucket',
            'region' => 'us-west-2',
            'key' => $key,
            'secret' => $secret,
            'token' => $token,
            'visibility' => 'private',
            'throw' => true,
        ];

        expect($this->config->get('filesystems.disks.craft-cloud'))
            ->toBe([
                ...$disk,
                'root' => 'environment-id/assets',
                'url' => 'https://cdn.craft.cloud/environment-id/assets',
            ])
            ->and($this->config->get('filesystems.disks.craft-cloud-uploads'))
            ->toBe([...$disk, 'root' => 'environment-id/storage/tmp/uploads']);
    });

    it('uses the function’s own credentials without an access key, and a storage endpoint if there is one', function (): void {
        $this->server('CRAFT_CLOUD_STORAGE_ENDPOINT', 'http://minio:9000');
        $this->server('CRAFT_CLOUD_CDN_BASE_URL', 'https://cdn.example.test/');

        $this->registerProvider();

        expect($this->config->get('filesystems.disks.craft-cloud'))
            ->not
            ->toHaveKeys(['key', 'secret', 'token'])
            ->toMatchArray([
                'endpoint' => 'http://minio:9000',
                'use_path_style_endpoint' => true,
                'url' => 'https://cdn.example.test/environment-id/assets',
            ]);
    });

    it('doesn’t register disks without a bucket', function (): void {
        unset($_SERVER['CRAFT_CLOUD_PROJECT_ID']);

        $this->registerProvider();

        expect($this->config->get('filesystems.disks.craft-cloud'))->toBeNull();
    });

    it('stages upload sessions in the bucket, unless the project chooses its own disk', function (
        ?string $configured,
        ?string $expected,
    ): void {
        $this->app->instance('events', new Dispatcher($this->app));
        Container::setInstance($this->app);
        $this->app->instance(GeneralConfig::class, GeneralConfig::create()->uploadSessionDisk($configured));
        $this->registerProvider();
        $this->bootProvider();

        $event = new UploadSessionStarting(Request::create('/'), 'handler', 'photo.jpg', 3, []);
        Event::dispatch($event);

        expect($event->disk)->toBe($expected);
    })->with([
        'default' => [null, 'craft-cloud-uploads'],
        'chosen by the project' => ['project-uploads', null],
    ]);
});
