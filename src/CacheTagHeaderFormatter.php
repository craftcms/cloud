<?php

declare(strict_types=1);

namespace craft\cloud;

use FOS\HttpCache\TagHeaderFormatter\TagHeaderFormatter;

/**
 * Writes Cloudflare's `Cache-Tag` header: the tags hashed with {@see CacheTagEncoder}, within Cloudflare's limits of
 * 1,000 tags and 16 KiB.
 *
 * When tags are dropped, `{environmentId}:overflow` is added first, so the page can still be purged by that tag.
 */
class CacheTagHeaderFormatter implements TagHeaderFormatter
{
    public function __construct(
        private readonly CacheTagEncoder $encoder,
    ) {}

    public function getTagsHeaderName(): string
    {
        return 'Cache-Tag';
    }

    public function getTagsHeaderValue(array $tags): string
    {
        $tags = $this->encoder->encode(array_values($tags));
        $bounded = $this->truncate($tags);

        if (count($bounded) !== count($tags)) {
            $bounded = $this->truncate([config('cloud.environment_id') . ':overflow', ...$tags]);
        }

        return implode(',', $bounded);
    }

    /** @param array<string> $tags
     * @return list<string>
     */
    private function truncate(array $tags): array
    {
        $result = [];
        $length = 0;

        foreach ($tags as $tag) {
            $length += strlen($tag) + ($result === [] ? 0 : 1);

            if (count($result) === 1000 || $length > 16_384) {
                break;
            }

            $result[] = $tag;
        }

        return $result;
    }
}
