<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Http\Events\CacheControlApplied;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Signer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

class StaticCache
{
    /** @var list<string> */
    private array $tagsToPurge = [];

    public function __construct(
        private readonly CacheTags $tags,
    ) {}

    public function apply(CacheControlApplied $event): void
    {
        $policy = clone $event->policy;
        $reasons = $event->reasons;

        if (
            !in_array($event->request->method(), ['GET', 'HEAD'], strict: true)
            || !$policy->isOk()
            || $event->response->getContent() === false
            || $event->request->cookies->has((string) config('session.cookie'))
            || $event->response->headers->has('Set-Cookie')
        ) {
            $policy->setPrivate();
            $policy->headers->addCacheControlDirective('no-store');
            $reasons[] = [
                'code' => 'cloud-ineligible',
                'target' => 'shared',
                'message' => 'Cloud caching excludes this method, status, response type, or cookie-bearing request/response.',
            ];
        }

        if ($policy->headers->hasCacheControlDirective('no-store')) {
            foreach (['s-maxage', 'stale-while-revalidate', 'stale-if-error'] as $directive) {
                $policy->headers->removeCacheControlDirective($directive);
            }
        }

        // Keep the browser policy independent of Cloudflare's shared lifetime.
        $event->response->headers->set('Cache-Control', $policy->headers->get('Cache-Control'));
        $sharedMaxAge = $policy->headers->getCacheControlDirective('s-maxage');

        if ($sharedMaxAge !== null) {
            $policy->setMaxAge((int) $sharedMaxAge);
            $policy->headers->removeCacheControlDirective('s-maxage');
        }

        // Always supply this, including for ESI responses with Surrogate-Control.
        // A pre-existing CDN header must not override core privacy or expiry caps.
        $event->response->headers->set('CDN-Cache-Control', $policy->headers->get('Cache-Control'));
        $tags = array_map($this->tags->dependency(...), $event->tags);
        $tags = [...$tags, ...explode(',', $event->response->headers->get('Cache-Tag', ''))];
        $header = $this->tags->header($tags);
        $event->response->headers->remove('Cache-Tag');

        if ($header !== '') {
            $event->response->headers->set('Cache-Tag', $header);
        }

        Log::debug('Cloud cache headers applied.', ['reasons' => $reasons]);
    }

    public function invalidate(ElementCachesInvalidated $event): void
    {
        if ($event->element && ElementHelper::isDraftOrRevision($event->element)) {
            return;
        }

        array_push($this->tagsToPurge, ...array_map($this->tags->dependency(...), $event->tags));
    }

    public function flush(): void
    {
        $tags = $this->tagsToPurge;
        $this->tagsToPurge = [];

        try {
            $this->purgeTags(...$tags);
        } catch (Throwable $exception) {
            Log::error('Failed to purge Cloud cache tags.', ['exception' => $exception, 'tags' => $tags]);
        }
    }

    public function purgeTags(string ...$tags): void
    {
        $tags = $this->tags->purge($tags);

        if ($tags === []) {
            return;
        }

        $environmentId = config('cloud.environment_id');
        $signingKey = config('cloud.signing_key');

        if (!$environmentId || !$signingKey) {
            throw new RuntimeException('Gateway API requests require an environment ID and signing key.');
        }

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
                rtrim(config('cloud.gateway_base_url', default: 'https://gateway.craft.cloud'), characters: '/')
                . '/api/'
                . rawurlencode($environmentId)
                . '/cache/purge',
                ['tags' => $tags],
            )
            ->throw();
    }
}
