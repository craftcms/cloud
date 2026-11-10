<?php

declare(strict_types=1);

namespace craft\cloud\Console;

use craft\cloud\Gateway;
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

    public function handle(Gateway $gateway): int
    {
        match ($this->action) {
            'purge-all' => $gateway->purgeAll(),
            'purge-cdn' => $gateway->purgeCdn(),
            'purge-origin' => $gateway->purgeOrigin(),
            'purge-prefixes' => $gateway->purgeUrlPrefixes(...(array) $this->argument('prefixes')),
            'purge-tags' => $gateway->purgeTags(...(array) $this->argument('tags')),
        };

        $this->components->info('Purged the static cache.');

        return self::SUCCESS;
    }
}
