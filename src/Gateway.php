<?php

declare(strict_types=1);

namespace craft\cloud;

use FOS\HttpCache\ProxyClient\Invalidation\ClearCapable;
use FOS\HttpCache\ProxyClient\Invalidation\PrefixCapable;
use FOS\HttpCache\ProxyClient\Invalidation\PurgeCapable;
use FOS\HttpCache\ProxyClient\Invalidation\RefreshCapable;
use FOS\HttpCache\ProxyClient\Invalidation\TagCapable;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Signer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The `cloud` HTTP cache client, which purges Craft Cloud's static cache through the signed gateway API.
 *
 * Invalidations are queued until {@see flush()}. Tags are hashed with {@see CacheTagEncoder}, so they match the `Cache-Tag`
 * header. URLs are purged by the `{environmentId}:uri:{path}` tag the gateway adds to the pages it caches, and the CDN
 * adds `{environmentId}:cdn` and `{environmentId}:rasterize` to assets and transforms.
 *
 * With the client's `soft_purge` option, tag invalidations are sent to the gateway's `cache/invalidate` endpoint, which
 * marks pages stale instead of removing them.
 */
class Gateway implements ClearCapable, PrefixCapable, PurgeCapable, RefreshCapable, TagCapable
{
    /** @var list<string> */
    private array $purgeTags = [];

    /** @var list<string> */
    private array $staleTags = [];

    /** @var list<string> */
    private array $fetchUrls = [];

    /** @var list<string> */
    private array $prefixes = [];

    public function __construct(
        private readonly CacheTagEncoder $encoder,
        private readonly bool $softPurge = false,
    ) {}

    public function invalidateTags(array $tags): static
    {
        $tags = $this->encoder->encode(array_values($tags));

        if ($this->softPurge) {
            array_push($this->staleTags, ...$tags);
        } else {
            array_push($this->purgeTags, ...$tags);
        }

        return $this;
    }

    public function purge(string $url, array $headers = []): static
    {
        // The gateway's tag for the URL's path, such as `/news/hello`, or `/` for the homepage.
        $path = '/' . trim((string) parse_url($url, PHP_URL_PATH), characters: '/');

        return $this->purgeGatewayTags(
            self::environmentId() . ':uri:'
                . preg_replace_callback(
                    '/[^\x21-\x2B\x2D-\x7E]/',
                    static fn(array $match): string => rawurlencode($match[0]),
                    $path,
                ),
        );
    }

    /**
     * Purges the URL, and has the gateway fetch it again, so the next visitor gets a cached page.
     */
    public function refresh(string $url, array $headers = []): static
    {
        $this->purge($url);
        $this->fetchUrls[] = $url;

        return $this;
    }

    public function invalidatePrefixes(array $prefixes): static
    {
        array_push($this->prefixes, ...array_values($prefixes));

        return $this;
    }

    /**
     * Purges pages, assets and transforms.
     */
    public function clear(): static
    {
        $environmentId = self::environmentId();

        return $this->purgeGatewayTags("{$environmentId}:uri", "{$environmentId}:cdn", "{$environmentId}:rasterize");
    }

    /**
     * Purges tags the gateway and CDN add themselves, such as `{environmentId}:uri`, which aren't encoded.
     */
    public function purgeGatewayTags(string ...$tags): static
    {
        array_push($this->purgeTags, ...$tags);

        return $this;
    }

    public function flush(): int
    {
        $purgeTags = array_values(array_unique($this->purgeTags));
        $staleTags = array_values(array_unique($this->staleTags));
        $fetchUrls = array_values(array_unique($this->fetchUrls));
        $prefixes = array_values(array_unique(array_filter($this->prefixes)));
        $this->purgeTags = [];
        $this->staleTags = [];
        $this->fetchUrls = [];
        $this->prefixes = [];
        $requests = 0;

        if ($purgeTags !== []) {
            $this->send(
                'purge',
                $fetchUrls === [] ? ['tags' => $purgeTags] : ['tags' => $purgeTags, 'fetchUrls' => $fetchUrls],
            );
            $requests++;
        }

        if ($staleTags !== []) {
            $this->send('invalidate', ['tags' => $staleTags]);
            $requests++;
        }

        if ($prefixes !== []) {
            $this->send('purge', ['prefixes' => $prefixes]);
            $requests++;
        }

        return $requests;
    }

    public static function environmentId(): string
    {
        $environmentId = config('cloud.environment_id');

        if (!is_string($environmentId) || $environmentId === '') {
            throw new RuntimeException('Static cache purges require an environment ID.');
        }

        return $environmentId;
    }

    /** @param array<string, list<string>> $payload */
    private function send(string $action, array $payload): void
    {
        $environmentId = self::environmentId();
        $signingKey = config('cloud.signing_key');

        if (!is_string($signingKey) || $signingKey === '') {
            throw new RuntimeException('Gateway API requests require a signing key.');
        }

        Log::info('Purging Craft Cloud static cache.', $payload);

        Http::withRequestMiddleware(static function (RequestInterface $request) use ($signingKey): RequestInterface {
            $created = time();

            return new Signer(new HmacSha256($signingKey))->sign(
                $request,
                ['@method', '@target-uri'],
                [
                    'signatureId' => 'sig',
                    'keyid' => 'hmac',
                    'created' => $created,
                    'expires' => $created + 300,
                    'tag' => 'craft-cloud',
                ],
            );
        })
            ->timeout(40)
            ->post(
                rtrim(
                    (string) config('cloud.gateway_base_url', default: 'https://gateway.craft.cloud'),
                    characters: '/',
                )
                . '/api/'
                . rawurlencode($environmentId)
                . "/cache/{$action}",
                $payload,
            )
            ->throw();
    }
}
