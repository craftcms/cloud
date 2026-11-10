<?php

declare(strict_types=1);

use craft\cloud\tests\Support\DnsRecords;
use CraftCms\Cms\ProjectConfig\ProjectConfig;

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

it('turns off writing project config YAML only on Craft Cloud', function (array $server, bool $expected): void {
    foreach ($server as $key => $value) {
        $this->server($key, $value);
    }

    $this->app->singleton(ProjectConfig::class, static fn() => new class extends ProjectConfig {
        public function __construct() {}
    });
    $this->registerProvider();

    expect($this->app->make(ProjectConfig::class)->writeYamlAutomatically)->toBe($expected);
})->with([
    'on Cloud' => [['CRAFT_CLOUD' => '1'], false],
    'off Cloud' => [[], true],
]);
