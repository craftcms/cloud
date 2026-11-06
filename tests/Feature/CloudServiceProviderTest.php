<?php

declare(strict_types=1);

use craft\cloud\tests\Support\DnsRecords;
use craft\cloud\transforms\CloudAssetTransformDriver;
use CraftCms\Cms\Asset\AssetTransformDrivers;
use CraftCms\Cms\Asset\AssetTransformerRegistry;
use CraftCms\Cms\Asset\AssetTransformers;
use CraftCms\Cms\Asset\Data\AssetTransformer;
use CraftCms\Cms\Config\GeneralConfig;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactoryContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;

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

it('registers the Cloud transform driver and profile in every environment', function (): void {
    $this->app->instance(AssetTransformers::class, new class extends AssetTransformers {
        public function __construct() {}
    });
    $this->app->instance(Container::class, $this->app);
    $this->app->instance('events', new Dispatcher($this->app));
    $this->app->instance(
        ValidationFactoryContract::class,
        new ValidationFactory(new Translator(new ArrayLoader(), 'en')),
    );

    $this->registerProvider();

    $profiles = collect($this->app->make(AssetTransformerRegistry::class)->all())
        ->map(fn(AssetTransformer $transformer): array => [$transformer->handle, $transformer->driver])
        ->values()
        ->all();

    expect($this->app->make(AssetTransformDrivers::class)->driver('craft-cloud'))
        ->toBeInstanceOf(CloudAssetTransformDriver::class)
        ->and($profiles)
        ->toBe([
            ['craft',      'craft'],
            ['craftCloud', 'craft-cloud'],
        ]);
});

it('makes Cloud transforms the default transformer on Craft Cloud', function (
    bool $onCloud,
    string $configured,
    string $expected,
): void {
    if ($onCloud) {
        $this->server('CRAFT_CLOUD', '1');
    }

    $this->app->singleton(GeneralConfig::class, fn(): GeneralConfig => GeneralConfig::create()->defaultAssetTransformer(
        $configured,
    ));

    $this->registerProvider();

    expect($this->app->make(GeneralConfig::class)->defaultAssetTransformer)->toBe($expected);
})->with([
    'on Cloud' => [true, 'craft', 'craftCloud'],
    'on Cloud with a project default' => [true, 'imgix', 'imgix'],
    'outside Cloud' => [false, 'craft', 'craft'],
]);
