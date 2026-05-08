<?php

declare(strict_types=1);

namespace craft\cloud;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class CloudServiceProvider extends ServiceProvider
{
    private const CACHE_FAILOVER_STORE = 'craft-cloud-failover';
    private const CACHE_REDIS_STORE = 'craft-cloud-redis';
    private const REDIS_CONNECTION = 'craft-cloud';
    private const SQS_CONNECTION = 'craft-cloud-sqs';

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
        $queueUrl = $_SERVER['CRAFT_CLOUD_SQS_URL'] ?? null;

        if (!$queueUrl) {
            return;
        }

        Config::set('queue.default', self::SQS_CONNECTION);
        Config::set('queue.connections.' . self::SQS_CONNECTION, [
            'driver' => 'sqs',
            'prefix' => '',
            'queue' => $queueUrl,
            'suffix' => '',
            'after_commit' => true,
        ]);
    }

    private function configureCache(): void
    {
        $stores = ['database', 'array'];
        $redisUrl = $this->resolveRedisUrl();

        if ($redisUrl) {
            $this->configureRedisCache($redisUrl);

            array_unshift($stores, self::CACHE_REDIS_STORE);
        }

        Config::set('cache.default', self::CACHE_FAILOVER_STORE);
        Config::set('cache.stores.' . self::CACHE_FAILOVER_STORE, [
            'driver' => 'failover',
            'stores' => $stores,
        ]);
    }

    private function configureRedisCache(string $redisUrl): void
    {
        Config::set('database.redis.' . self::REDIS_CONNECTION, [
            'url' => $redisUrl,
            'database' => 0,
        ]);

        Config::set('cache.stores.' . self::CACHE_REDIS_STORE, [
            'driver' => 'redis',
            'connection' => self::REDIS_CONNECTION,
            'lock_connection' => self::REDIS_CONNECTION,
        ]);
    }

    private function resolveRedisUrl(): ?string
    {
        $srv = $_SERVER['CRAFT_CLOUD_CACHE_SRV'] ?? null;

        if ($srv) {
            $records = dns_get_record($srv, DNS_SRV);

            $target = is_array($records) ? $records[0]['target'] ?? null : null;
            $port = is_array($records) ? $records[0]['port'] ?? null : null;

            if ($target !== null && $port !== null) {
                return 'redis://' . $target . ':' . $port;
            }
        }

        return null;
    }

    private function isCraftCloud(): bool
    {
        return ($_SERVER['CRAFT_CLOUD'] ?? null) !== null;
    }
}
