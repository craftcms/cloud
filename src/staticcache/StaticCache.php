<?php

declare(strict_types=1);

namespace craft\cloud\staticcache;

use CraftCms\DependencyAwareCache\Events\TagsInvalidated;
use Throwable;

/**
 * Mirrors Craft's tag dependency cache in Craft Cloud's static cache.
 *
 * Pages are tagged with the Craft cache tags they depend on, and every `TagDependency::invalidate()` purges the
 * pages with those tags. Purges are collected until the request, command or queue job is done, then sent as one
 * queued {@see PurgeStaticCache} job.
 */
class StaticCache
{
    private const int MAX_TAGS = 1000;
    private const int MAX_TAG_BYTES = 16 * 1024;

    /** @var array<string, true> */
    private array $tags = [];

    /**
     * Cloudflare cache tags are shared by every environment in a zone, and limited to 16 KiB per response, so Craft
     * tags are hashed and prefixed with the start of the environment ID.
     */
    public static function tag(string $craftTag): string
    {
        return substr(self::environmentId(), offset: 0, length: 8) . dechex(crc32($craftTag));
    }

    /**
     * Returns the `Cache-Tag` header for a page's Craft tags, within Cloudflare's limits of 1,000 tags and 16 KiB.
     *
     * @param list<string> $craftTags
     */
    public static function header(array $craftTags): string
    {
        $tags = array_values(array_unique(array_map(self::tag(...), $craftTags)));
        $header = implode(',', $tags);

        if (count($tags) <= self::MAX_TAGS && strlen($header) <= self::MAX_TAG_BYTES) {
            return $header;
        }

        // Pages that lose tags are purged with every invalidation instead.
        $header = implode(',', [self::overflowTag(), ...array_slice($tags, offset: 0, length: self::MAX_TAGS - 1)]);

        return strlen($header) <= self::MAX_TAG_BYTES
            ? $header
            : substr(
                $header,
                offset: 0,
                length: (int) strrpos(substr($header, offset: 0, length: self::MAX_TAG_BYTES + 1), needle: ','),
            );
    }

    /**
     * Added to pages that have more tags than fit in the `Cache-Tag` header, and purged with every invalidation.
     */
    public static function overflowTag(): string
    {
        return self::environmentId() . ':overflow';
    }

    public static function environmentId(): string
    {
        return (string) ($_SERVER['CRAFT_CLOUD_ENVIRONMENT_ID'] ?? '');
    }

    public function handleTagsInvalidated(TagsInvalidated $event): void
    {
        $tags = (array) $event->tags;

        // Core invalidates `element::{class}::*` for every draft autosave, which would purge every listing page.
        if (preg_grep('/^element::.+::(drafts|revisions)$/', $tags)) {
            return;
        }

        // Core's element index and search query caches, which pages never depend on, are invalidated on every save.
        foreach (preg_grep('/^element-(index|search)-query::/', $tags, PREG_GREP_INVERT) as $tag) {
            $this->tags[self::tag($tag)] = true;
        }
    }

    public function flush(): void
    {
        if ($this->tags === []) {
            return;
        }

        $tags = [...array_keys($this->tags), self::overflowTag()];
        $this->tags = [];

        try {
            PurgeStaticCache::dispatch($tags);
        } catch (Throwable $exception) {
            // On Lambda, termination runs before the response is returned, so don't fail a request that succeeded.
            report($exception);
        }
    }
}
