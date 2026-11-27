<?php

declare(strict_types=1);

namespace craft\cloud;

/**
 * Hashes tags to `{shortEnvironmentId}{crc32}`, so they're short, safe in a header, and unique to the environment.
 *
 * {@see CacheTagHeaderFormatter} uses it for the `Cache-Tag` header and {@see Gateway} for tag purges, so purges match
 * what was sent.
 */
class CacheTagEncoder
{
    /** @param list<string> $tags
     * @return list<string>
     */
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
