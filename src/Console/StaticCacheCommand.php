<?php

declare(strict_types=1);

namespace craft\cloud\Console;

use craft\cloud\Gateway;
use craft\cloud\StaticCache;
use CraftCms\Cms\HttpCache\HttpCacheManager;
use Illuminate\Console\Command;

/**
 * Purges Craft Cloud's static cache, like the 3.x `cloud/static-cache/*` commands.
 */
class StaticCacheCommand extends Command
{
    private const array COMMANDS = [
        'purge-all' => 'Purges the static cache for pages, assets and transforms.',
        'purge-cdn' => 'Purges the static cache for assets and transforms.',
        'purge-origin' => 'Purges the static cache for pages.',
        'purge-prefixes {prefixes* : URL prefixes, such as example.com/blog}' => 'Purges static cache URLs by prefix.',
        'purge-tags {tags* : Cache tags}' => 'Purges static cache tags.',
    ];

    public function __construct(
        private readonly string $action,
        string $signature,
        string $description,
    ) {
        $this->signature = $signature;
        $this->description = $description;

        parent::__construct();
    }

    /** @return list<self> */
    public static function all(): array
    {
        $commands = [];

        foreach (self::COMMANDS as $signature => $description) {
            $action = explode(' ', $signature, limit: 2)[0];
            $commands[] = new self($action, "cloud:static-cache:{$signature}", $description);
        }

        return $commands;
    }

    public function handle(HttpCacheManager $httpCache): int
    {
        $invalidator = $httpCache->invalidator(StaticCache::CLIENT);
        /** @var Gateway $gateway */
        $gateway = $httpCache->client(StaticCache::CLIENT);
        $environmentId = Gateway::environmentId();

        match ($this->action) {
            'purge-all' => $invalidator->clearCache(),
            'purge-cdn' => $gateway->purgeGatewayTags("{$environmentId}:cdn", "{$environmentId}:rasterize"),
            'purge-origin' => $gateway->purgeGatewayTags("{$environmentId}:uri"),
            'purge-prefixes' => $invalidator->invalidatePrefixes((array) $this->argument('prefixes')),
            'purge-tags' => $invalidator->invalidateTags((array) $this->argument('tags')),
        };

        $invalidator->flush();

        $this->components->info('Purged the static cache.');

        return self::SUCCESS;
    }
}
