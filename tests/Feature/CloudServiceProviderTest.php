<?php

declare(strict_types=1);

use CraftCms\Cloud\Tests\Support\DnsRecords;

it('leaves the app alone outside Craft Cloud', function (): void {
    $this->boot(['SESSION_DRIVER' => 'file', 'LOG_CHANNEL' => 'stack']);

    expect(config('cache.default'))
        ->not
        ->toBe('craft-cloud-failover')
        ->and(config('cache.stores.craft-cloud-failover'))
        ->toBeNull()
        ->and(config('queue.connections.craft-cloud-sqs'))
        ->toBeNull()
        ->and(config('logging.default'))
        ->toBe('stack')
        ->and(config('session.driver'))
        ->toBe('file');
});

it('detects Craft Cloud from CRAFT_CLOUD or the Lambda runtime', function (array $env): void {
    $this->boot($env);

    expect(config('cache.default'))->toBe('craft-cloud-failover');
})->with([
    'CRAFT_CLOUD' => [['CRAFT_CLOUD' => '1']],
    'AWS_LAMBDA_RUNTIME_API' => [['AWS_LAMBDA_RUNTIME_API' => '127.0.0.1:9001']],
]);

it('sends logs to stderr on Craft Cloud, whatever the project chose', function (): void {
    $this->boot(['CRAFT_CLOUD' => '1', 'LOG_CHANNEL' => 'daily']);

    expect(config('logging.default'))->toBe('stderr');
});

it('uses database sessions instead of file or cookie sessions on Craft Cloud', function (
    string $driver,
    string $expected,
): void {
    $this->boot(['CRAFT_CLOUD' => '1', 'SESSION_DRIVER' => $driver]);

    expect(config('session.driver'))->toBe($expected);
})->with([
    'file' => ['file', 'database'],
    'cookie' => ['cookie', 'database'],
    'redis is kept' => ['redis', 'redis'],
]);

it('configures the Craft Cloud SQS queue connection', function (): void {
    $this->boot([
        'CRAFT_CLOUD' => '1',
        'CRAFT_CLOUD_SQS_URL' => 'https://sqs.us-east-1.amazonaws.com/123456789012/craft-cloud',
    ]);

    expect(config('queue.default'))
        ->toBe('craft-cloud-sqs')
        ->and(config('queue.connections.craft-cloud-sqs'))
        ->toBe([
            'driver' => 'sqs',
            'prefix' => '',
            'queue' => 'https://sqs.us-east-1.amazonaws.com/123456789012/craft-cloud',
            'suffix' => '',
            'after_commit' => true,
        ]);
});

it('leaves the queue alone on Craft Cloud without an SQS URL', function (): void {
    $this->boot(['CRAFT_CLOUD' => '1']);

    expect(config('queue.connections.craft-cloud-sqs'))->toBeNull();
});

it('falls back from the database to memory when there is no cache SRV record', function (): void {
    $this->boot(['CRAFT_CLOUD' => '1', 'CRAFT_CLOUD_CACHE_SRV' => '_cache._tcp.example.test']);

    expect(config('cache.stores.craft-cloud-failover'))
        ->toBe([
            'driver' => 'failover',
            'stores' => ['database', 'array'],
        ])
        ->and(config('cache.stores.craft-cloud-redis'))
        ->toBeNull();
});

it('puts Valkey first, with short timeouts, when the cache SRV record resolves', function (): void {
    DnsRecords::fake([['target' => 'valkey.internal', 'port' => 6380]]);

    $this->boot(['CRAFT_CLOUD' => '1', 'CRAFT_CLOUD_CACHE_SRV' => '_cache._tcp.example.test']);

    expect(config('database.redis.craft-cloud'))
        ->toBe([
            'url' => 'redis://valkey.internal:6380',
            'database' => 0,
            'timeout' => 1,
            'read_timeout' => 1,
        ])
        ->and(config('cache.stores.craft-cloud-redis'))
        ->toBe([
            'driver' => 'redis',
            'connection' => 'craft-cloud',
            'lock_connection' => 'craft-cloud',
        ])
        ->and(config('cache.stores.craft-cloud-failover.stores'))
        ->toBe(['craft-cloud-redis', 'database', 'array']);
});
