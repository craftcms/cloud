<?php

declare(strict_types=1);

namespace CraftCms\Cloud;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class CloudServiceProvider extends ServiceProvider
{
    private const CACHE_FAILOVER_STORE = 'craft-cloud-failover';
    private const CACHE_REDIS_STORE = 'craft-cloud-redis';
    private const REDIS_CONNECTION = 'craft-cloud';
    private const SQS_CONNECTION = 'craft-cloud-sqs';

    /**
     * Seconds to wait on Valkey before the failover cache moves on to the database.
     */
    private const REDIS_TIMEOUT_SECONDS = 1;

    public function register(): void
    {
        if (!Cloud::isCraftCloud()) {
            return;
        }

        $this->configureQueue();
        $this->configureCache();
        $this->configureLogging();
        $this->configureSession();
    }

    /**
     * Lambda only collects stderr. bref/laravel-bridge switches the default channel only when it's `stack`, so a
     * project's `daily` or `single` channel would otherwise write to `/tmp` and be lost.
     */
    private function configureLogging(): void
    {
        Config::set('logging.default', 'stderr');
    }

    /**
     * Each Lambda container has its own `/tmp`, so `file` sessions don't work, and bref/laravel-bridge replaces them
     * with `cookie` sessions, which are capped at 4 KB. Craft creates the `sessions` table, so use it.
     */
    private function configureSession(): void
    {
        if (in_array(Config::get('session.driver'), ['file', 'cookie'], strict: true)) {
            Config::set('session.driver', 'database');
        }
    }

    private function configureQueue(): void
    {
        $queueUrl = Cloud::env('CRAFT_CLOUD_SQS_URL');

        if ($queueUrl === null) {
            return;
        }

        // The SQS client gets its region and credentials from the function's AWS environment.
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

        if ($redisUrl !== null) {
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
            'timeout' => self::REDIS_TIMEOUT_SECONDS,
            'read_timeout' => self::REDIS_TIMEOUT_SECONDS,
        ]);

        Config::set('cache.stores.' . self::CACHE_REDIS_STORE, [
            'driver' => 'redis',
            'connection' => self::REDIS_CONNECTION,
            'lock_connection' => self::REDIS_CONNECTION,
        ]);
    }

    private function resolveRedisUrl(): ?string
    {
        $srv = Cloud::env('CRAFT_CLOUD_CACHE_SRV');

        if ($srv === null) {
            return null;
        }

        $records = dns_get_record($srv, DNS_SRV);
        $target = is_array($records) ? $records[0]['target'] ?? null : null;
        $port = is_array($records) ? $records[0]['port'] ?? null : null;

        if ($target === null || $port === null) {
            return null;
        }

        return 'redis://' . $target . ':' . $port;
    }
}
