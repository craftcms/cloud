<?php

declare(strict_types=1);

namespace craft\cloud\staticcache;

use Illuminate\Console\Command;

class PurgeCommand extends Command
{
    #[\Override]
    protected $signature = 'cloud:purge
        {--tag=* : Purge pages, assets and transforms with these Cloudflare cache tags}
        {--prefix=* : Purge URLs that start with these prefixes, such as example.com/blog}';

    #[\Override]
    protected $description = 'Purges Craft Cloud’s static cache. Purges everything unless tags or prefixes are given.';

    public function handle(): int
    {
        $tags = (array) $this->option('tag');
        $prefixes = (array) $this->option('prefix');

        dispatch_sync(
            $tags === [] && $prefixes === []
                ? PurgeStaticCache::everything()
                : new PurgeStaticCache(array_values($tags), array_values($prefixes)),
        );

        $this->components->info('Purged the static cache.');

        return self::SUCCESS;
    }
}
