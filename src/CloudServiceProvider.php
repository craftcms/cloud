<?php

declare(strict_types=1);

namespace craft\cloud;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;

class CloudServiceProvider extends ServiceProvider
{
    private const CACHE_STORE = 'craft-cloud';
    private const CACHE_REDIS_CONNECTION = 'craft-cloud-cache';
    private const CACHE_REDIS_STORE = 'craft-cloud-redis';
    private const QUEUE_CONNECTION = 'craft-cloud-sqs';

    #[Override]
    public function register(): void
    {
        if (!$this->isCraftCloud()) {
            return;
        }

        $this->configureQueue();
        $this->configureCache();
    }

    private function configureQueue(): void
    {
        $queueUrl = $this->env('CRAFT_CLOUD_SQS_URL');

        if (!$queueUrl) {
            return;
        }

        Config::set('queue.default', self::QUEUE_CONNECTION);
        Config::set("queue.connections." . self::QUEUE_CONNECTION, [
            'driver' => 'sqs',
            'key' => $this->env('AWS_ACCESS_KEY_ID'),
            'secret' => $this->env('AWS_SECRET_ACCESS_KEY'),
            'token' => $this->env('AWS_SESSION_TOKEN'),
            'prefix' => '',
            'queue' => $queueUrl,
            'suffix' => '',
            'region' => $this->env('AWS_DEFAULT_REGION') ?? $this->env('AWS_REGION') ?? 'us-east-1',
            'after_commit' => true,
        ]);
    }

    private function configureCache(): void
    {
        $stores = ['database', 'array'];

        if ($redisUrl = $this->resolveRedisUrl()) {
            $this->configureRedisCache($redisUrl);

            array_unshift($stores, self::CACHE_REDIS_STORE);
        }

        Config::set('cache.default', self::CACHE_STORE);
        Config::set("cache.stores." . self::CACHE_STORE, [
            'driver' => 'failover',
            'stores' => $stores,
        ]);
    }

    private function configureRedisCache(string $redisUrl): void
    {
        Config::set("database.redis." . self::CACHE_REDIS_CONNECTION, [
            'url' => $redisUrl,
            'database' => 0,
        ]);

        Config::set("cache.stores." . self::CACHE_REDIS_STORE, [
            'driver' => 'redis',
            'connection' => self::CACHE_REDIS_CONNECTION,
            'lock_connection' => self::CACHE_REDIS_CONNECTION,
        ]);
    }

    private function resolveRedisUrl(): ?string
    {
        $srv = $this->env('CRAFT_CLOUD_CACHE_SRV');

        if ($srv) {
            $records = dns_get_record($srv, DNS_SRV);

            if (is_array($records) && isset($records[0]['target'], $records[0]['port'])) {
                return "redis://{$records[0]['target']}:{$records[0]['port']}";
            }
        }

        return $this->env('CRAFT_CLOUD_REDIS_URL');
    }

    private function isCraftCloud(): bool
    {
        return $this->env('CRAFT_CLOUD') !== null || $this->env('AWS_LAMBDA_RUNTIME_API') !== null;
    }

    private function env(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
