<?php

declare(strict_types=1);

namespace craft\cloud\staticcache;

use craft\cloud\signing\RequestSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Purges Craft Cloud's static cache by tag or URL prefix, through the gateway API.
 *
 * The gateway tags every page with `{environmentId}:uri` and `{environmentId}:uri:{uri}`, and the CDN tags assets
 * and transforms with `{environmentId}:cdn` and `{environmentId}:rasterize`.
 */
class PurgeStaticCache implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param list<string> $tags
     * @param list<string> $prefixes
     */
    public function __construct(
        public readonly array $tags = [],
        public readonly array $prefixes = [],
    ) {}

    public static function everything(): self
    {
        $environmentId = StaticCache::environmentId();

        return new self(["{$environmentId}:uri", "{$environmentId}:cdn", "{$environmentId}:rasterize"]);
    }

    public function handle(RequestSigner $signer): void
    {
        $baseUrl = rtrim($_SERVER['CRAFT_CLOUD_GATEWAY_BASE_URL'] ?? 'https://gateway.craft.cloud', characters: '/');

        Http::withRequestMiddleware($signer->sign(...))
            ->timeout(40)
            ->post("{$baseUrl}/api/" . rawurlencode(StaticCache::environmentId()) . '/cache/purge', array_filter([
                'tags' => $this->tags,
                'prefixes' => $this->prefixes,
            ]))
            ->throw();
    }
}
