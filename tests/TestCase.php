<?php

declare(strict_types=1);

namespace CraftCms\Cloud\Tests;

use CraftCms\Cloud\CloudServiceProvider;
use CraftCms\Cms\Providers\CraftServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Boots a real Laravel app with Craft's and Cloud's service providers.
 *
 * Cloud's provider reads the function's environment when it registers, so tests set environment variables with
 * {@see self::boot()}, which then recreates the app.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Environment variables cleared before each test and restored after it.
     */
    private const ENV_KEYS = [
        'AWS_LAMBDA_RUNTIME_API',
        'CRAFT_CLOUD',
        'CRAFT_CLOUD_REDIS_URL',
        'CRAFT_CLOUD_ENVIRONMENT_ID',
        'CRAFT_CLOUD_PROJECT_ID',
        'CRAFT_CLOUD_SQS_URL',
        'LAMBDA_TASK_ROOT',
        'LOG_CHANNEL',
        'SESSION_DRIVER',
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->originalEnv[$key] = getenv($key);
            $this->unsetEnv($key);
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->originalEnv as $key => $value) {
            $value === false ? $this->unsetEnv($key) : $this->setEnv($key, $value);
        }
    }

    /**
     * Sets environment variables and recreates the app, so providers register with them.
     *
     * @param array<string, string> $env
     */
    protected function boot(array $env = []): void
    {
        foreach ($env as $key => $value) {
            $this->setEnv($key, $value);
        }

        $this->refreshApplication();
    }

    protected function defineEnvironment($app): void
    {
        // Craft logs that it isn't installed on every boot. Keep that out of test output, including on Craft Cloud,
        // where the provider switches the default channel to stderr.
        $app['config']->set('logging.channels.stderr', [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ]);
        $app['config']->set('logging.channels.stack', [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            CraftServiceProvider::class,
            CloudServiceProvider::class,
        ];
    }

    private function setEnv(string $key, string $value): void
    {
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function unsetEnv(string $key): void
    {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }
}
