<?php

declare(strict_types=1);

namespace craft\cloud\tests;

use craft\cloud\CloudServiceProvider;
use craft\cloud\tests\Support\DnsRecords;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private const SERVER_KEYS = [
        'CRAFT_CLOUD',
        'CRAFT_CLOUD_CACHE_SRV',
        'CRAFT_CLOUD_PROJECT_ID',
        'CRAFT_CLOUD_SIGNING_KEY',
        'CRAFT_CLOUD_SQS_URL',
    ];

    protected Container $app;

    protected Repository $config;

    private array $serverValues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverValues = [];

        foreach (self::SERVER_KEYS as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $this->serverValues[$key] = $_SERVER[$key];
            }

            unset($_SERVER[$key]);
        }

        DnsRecords::reset();

        $this->app = new Container();
        $this->config = new Repository([
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => [
                        'driver' => 'array',
                    ],
                    'database' => [
                        'driver' => 'database',
                    ],
                ],
            ],
            'database' => [
                'redis' => [],
            ],
            'logging' => [
                'default' => 'stack',
                'channels' => [
                    'stack' => [
                        'driver' => 'stack',
                        'channels' => ['single'],
                    ],
                    'stderr' => [
                        'driver' => 'monolog',
                    ],
                    'emergency' => [
                        'path' => '/var/task/storage/logs/laravel.log',
                    ],
                ],
            ],
            'queue' => [
                'default' => 'sync',
                'connections' => [
                    'sync' => [
                        'driver' => 'sync',
                    ],
                ],
            ],
        ]);

        $this->app->instance('config', $this->config);

        Container::setInstance($this->app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            unset($_SERVER[$key]);
        }

        foreach ($this->serverValues as $key => $value) {
            $_SERVER[$key] = $value;
        }

        DnsRecords::reset();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    protected function registerProvider(): void
    {
        $provider = new CloudServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    protected function server(string $key, string $value): void
    {
        $_SERVER[$key] = $value;
    }
}
