<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\HttpCache\TagEncoder;

/**
 * Hashes tags to `{shortEnvironmentId}{crc32}`, so they're short, safe in a header, and unique to the environment.
 *
 * Craft uses it for the `Cache-Tag` header and for tag purges, so purges match what was sent.
 */
class CacheTagEncoder implements TagEncoder
{
    public function encode(array $tags): array
    {
        $prefix = substr((string) config('cloud.environment_id'), offset: 0, length: 8);

        return array_values(array_unique(array_map(static fn(string $tag): string => sprintf(
            '%s%x',
            $prefix,
            crc32($tag),
        ), $tags)));
    }
}
