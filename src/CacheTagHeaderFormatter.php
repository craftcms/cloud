<?php

declare(strict_types=1);

namespace craft\cloud;

use FOS\HttpCache\TagHeaderFormatter\TagHeaderFormatter;

/**
 * Writes Cloudflare's `Cache-Tag` header within its limits of 1,000 tags and 16 KiB. Tags are already short hashes
 * from {@see CacheTagEncoder}.
 *
 * When tags are dropped, `{environmentId}:overflow` is added first, so the page can still be purged by that tag.
 */
class CacheTagHeaderFormatter implements TagHeaderFormatter
{
    public function getTagsHeaderName(): string
    {
        return 'Cache-Tag';
    }

    public function getTagsHeaderValue(array $tags): string
    {
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
