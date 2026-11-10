<?php

declare(strict_types=1);

namespace craft\cloud;

/** Cloud gateway/Cloudflare wire encoding, shared by collection and invalidation. */
class CacheTags
{
    public function dependency(string $tag): string
    {
        return $tag === ''
            ? ''
            : sprintf('%s%x', substr((string) config('cloud.environment_id'), offset: 0, length: 8), crc32($tag));
    }

    /** @param list<string> $tags */
    public function header(array $tags): string
    {
        $tags = $this->normalize($tags);
        $bounded = $this->truncate($tags);

        if (count($bounded) !== count($tags)) {
            $bounded = $this->truncate([$this->overflow(), ...$tags]);
        }

        return implode(',', $bounded);
    }

    /** @param list<string> $tags
     * @return list<string>
     */
    public function purge(array $tags): array
    {
        return array_values(array_unique(array_map(fn(string $tag): string => strlen($tag) > 1024
            ? $this->overflow()
            : $tag, $this->normalize($tags))));
    }

    /** @param list<string> $tags
     * @return list<string>
     */
    private function normalize(array $tags): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn(string $tag): string => preg_replace_callback(
                '/[^\x21-\x2B\x2D-\x7E]/',
                static fn(array $match): string => rawurlencode($match[0]),
                trim($tag),
            ), $tags),
            static fn(string $tag): bool => $tag !== '',
        )));
    }

    private function overflow(): string
    {
        return config('cloud.environment_id') . ':overflow';
    }

    /** @param list<string> $tags
     * @return list<string>
     */
    private function truncate(array $tags): array
    {
        $result = [];
        $length = 0;

        foreach ($tags as $tag) {
            $length += strlen($tag) + ($result === [] ? 0 : 1);

            if (count($result) === 1000 || strlen($tag) > 1024 || $length > 16_384) {
                break;
            }

            $result[] = $tag;
        }

        return $result;
    }
}
