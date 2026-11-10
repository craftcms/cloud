<?php

declare(strict_types=1);

namespace craft\cloud;

use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Signer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Purges Craft Cloud's static cache through the signed gateway API.
 *
 * The gateway adds `{environmentId}:uri` and `{environmentId}:uri:{uri}` tags to the pages it caches, and the CDN
 * adds `{environmentId}:cdn` and `{environmentId}:rasterize` to assets and transforms.
 */
class Gateway
{
    public function __construct(
        private readonly CacheTags $tags,
    ) {}

    public function purgeAll(): void
    {
        $environmentId = self::environmentId();
        $this->purgeTags("{$environmentId}:uri", "{$environmentId}:cdn", "{$environmentId}:rasterize");
    }

    public function purgeOrigin(): void
    {
        $this->purgeTags(self::environmentId() . ':uri');
    }

    public function purgeCdn(): void
    {
        $environmentId = self::environmentId();
        $this->purgeTags("{$environmentId}:cdn", "{$environmentId}:rasterize");
    }

    public function purgeTags(string ...$tags): void
    {
        $this->purge($tags);
    }

    /** @param list<string> $tags
     * @param list<string> $fetchUrls URLs the gateway refetches after purging, so the next visitor gets a cached page.
     */
    public function purge(array $tags, array $fetchUrls = []): void
    {
        $tags = $this->tags->purge($tags);
        $fetchUrls = array_values(array_unique($fetchUrls));

        if ($tags !== []) {
            $this->send($fetchUrls === [] ? ['tags' => $tags] : ['tags' => $tags, 'fetchUrls' => $fetchUrls]);
        }
    }

    public function purgeUrlPrefixes(string ...$prefixes): void
    {
        $prefixes = array_values(array_unique(array_filter($prefixes)));

        if ($prefixes !== []) {
            $this->send(['prefixes' => $prefixes]);
        }
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
    private function send(array $payload): void
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
                . '/cache/purge',
                $payload,
            )
            ->throw();
    }
}
