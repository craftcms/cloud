<?php

namespace craft\cloud;

use Craft;
use craft\base\ElementInterface;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use craft\web\View;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Support\Collection;
use League\Uri\Components\Path;
use samdark\log\PsrMessage;
use yii\base\Event;
use yii\caching\TagDependency;

/**
 * Static Cache tags can appear in the `Cache-Tag` and `Cache-Purge-Tag` headers.
 * The values are comma-separated and can be in several formats:
 *
 * - Added by the gateway:
 *   - `{environmentId}`
 *   - `{environmentId}:{uri}` (URI has a leading and no trailing slash)
 * - Added by the CDN:
 *    - `cdn:{environmentId}`
 *    - `cdn:{environmentId}:{objectKey}` (object key has no leading slash)
 * - Added by Craft:
 *   - `{environmentShortId}{hashed}`
 */
class StaticCache extends \yii\base\Component
{
    public const CDN_PREFIX = 'cdn:';

    private const SOURCE_CDN_CACHE_CONTROL = 'cdn-cache-control';
    private const SOURCE_CACHE_CONTROL = 'cache-control';
    private const SOURCE_DEFAULT = 'cloud-default';
    private const HEADER_PRAGMA = 'Pragma';
    private const HEADER_EXPIRES = 'Expires';

    private const TRACKED_HEADERS = [
        HeaderEnum::CACHE_CONTROL->value,
        HeaderEnum::CDN_CACHE_CONTROL->value,
        self::HEADER_PRAGMA,
        self::HEADER_EXPIRES,
        HeaderEnum::SET_COOKIE->value,
        HeaderEnum::SURROGATE_CONTROL->value,
    ];

    private ?int $cacheDuration = null;
    private Collection $tags;
    private Collection $tagsToPurge;
    private bool $collectingCacheInfo = false;

    public function init(): void
    {
        $this->tags = Collection::make();
        $this->tagsToPurge = Collection::make();
    }

    public function registerEventHandlers(): void
    {
        Event::on(
            \craft\web\Application::class,
            \craft\web\Application::EVENT_INIT,
            fn(Event $event) => $this->handleInitWebApplication($event),
        );

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            fn(TemplateEvent $event) => $this->handleBeforeRenderPageTemplate($event),
        );

        Event::on(
            \craft\web\Response::class,
            \yii\web\Response::EVENT_AFTER_PREPARE,
            fn(Event $event) => $this->handleAfterPrepareWebResponse($event),
        );

        Event::on(
            Elements::class,
            Elements::EVENT_INVALIDATE_CACHES,
            fn(InvalidateElementCachesEvent $event) => $this->handleInvalidateElementCaches($event),
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            fn(ElementEvent $event) => $this->handleSaveElement($event),
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            fn(ElementEvent $event) => $this->handleDeleteElement($event),
        );

        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            fn(RegisterCacheOptionsEvent $event) => $this->handleRegisterCacheOptions($event),
        );

        Craft::$app->onAfterRequest(function() {
            if ($this->tagsToPurge->isNotEmpty()) {
                try {
                    $this->purgeTags(...$this->tagsToPurge);
                } catch (\Throwable $e) {
                    // TODO: log exception once output payload isn't a concern
                    Craft::error('Failed to purge tags after request', __METHOD__);
                }
            }
        });
    }

    private function handleInitWebApplication(Event $event): void
    {
        if (!$this->isCacheable()) {
            return;
        }

        Craft::$app->getElements()->startCollectingCacheInfo();
        $this->collectingCacheInfo = true;
    }

    private function handleAfterPrepareWebResponse(Event $event): void
    {
        if (!$this->isCacheable()) {
            return;
        }

        if ($this->collectingCacheInfo) {
            /** @var TagDependency|null $dependency */
            /** @var int|null $duration */
            [$dependency, $duration] = Craft::$app->getElements()->stopCollectingCacheInfo();
            $this->collectingCacheInfo = false;
            $tags = Collection::make($dependency?->tags ?? [])->map(fn(string $tag) => StaticCacheTag::create($tag)->minify(true));
            $this->tags->push(...$tags);
            $this->cacheDuration = $duration;
        }

        $this->addCacheHeadersToWebResponse();
    }

    private function handleBeforeRenderPageTemplate(TemplateEvent $event): void
    {
        /** @var UrlManager $urlManager */
        $urlManager = Craft::$app->getUrlManager();
        $matchedElement = $urlManager->getMatchedElement();

        if ($matchedElement) {
            Craft::$app->getElements()->collectCacheInfoForElement($matchedElement);
        }
    }

    private function handleInvalidateElementCaches(InvalidateElementCachesEvent $event): void
    {
        $tags = Collection::make($event->tags)->map(fn(string $tag) => StaticCacheTag::create($tag)->minify(true));

        $skip = $tags->contains(function(StaticCacheTag $tag) {
            return preg_match('/element::craft\\\\elements\\\\\S+::(drafts|revisions)/', $tag->originalValue);
        });

        if ($skip) {
            return;
        }

        $this->tagsToPurge->push(...$tags);
    }

    private function handleRegisterCacheOptions(RegisterCacheOptionsEvent $event): void
    {
        $event->options[] = [
            'key' => 'craft-cloud-caches',
            'label' => Craft::t('app', 'Craft Cloud caches'),
            'action' => [$this, 'purgeAll'],
        ];
    }

    private function handleSaveElement(ElementEvent $event): void
    {
        $this->purgeElementUri($event->element);
    }

    private function handleDeleteElement(ElementEvent $event): void
    {
        $this->purgeElementUri($event->element);
    }

    public function purgeAll(): void
    {
        $this->purgeGateway();
        $this->purgeCdn();
    }

    public function purgeGateway(): void
    {
        $this->tagsToPurge->push(Module::getInstance()->getConfig()->environmentId);
    }

    public function purgeCdn(): void
    {
        $this->tagsToPurge->push(self::CDN_PREFIX . Module::getInstance()->getConfig()->environmentId);
    }

    private function purgeElementUri(ElementInterface $element): void
    {
        $uri = $element->uri ?? null;

        if (ElementHelper::isDraftOrRevision($element) || !$uri) {
            return;
        }

        $uri = $element->getIsHomepage()
            ? '/'
            : Path::new($uri)->withLeadingSlash()->withoutTrailingSlash();

        $environmentId = Module::getInstance()->getConfig()->environmentId;
        $this->tagsToPurge->prepend("$environmentId:$uri");
    }

    private function addCacheHeadersToWebResponse(): void
    {
        $headers = Craft::$app->getResponse()->getHeaders();
        $decision = $this->staticCacheDecision();

        $headers->setDefault(
            HeaderEnum::CDN_CACHE_CONTROL->value,
            $decision['directives']->implode(','),
        );

        Craft::info(new PsrMessage('Resolved static cache directives', [
            'decision' => $decision,
        ]), __METHOD__);

        // Capture and remove any existing headers, so we can prepare them
        $existingTagsFromHeader = Collection::make($headers->get(HeaderEnum::CACHE_TAG->value, first: false) ?? []);
        $headers->remove(HeaderEnum::CACHE_TAG->value);
        $this->tags->push(...$existingTagsFromHeader);
        $this->tags = $this->prepareTags(...$this->tags);

        Craft::info(new PsrMessage('Adding cache tags to response', [
            'tags' => $this->tags,
        ]), __METHOD__);

        $this->tags
            ->each(function(StaticCacheTag $tag) use ($headers) {
                $headers->add(
                    HeaderEnum::CACHE_TAG->value,
                    $tag->getValue(),
                );
            });
    }

    public function purgeTags(string|StaticCacheTag ...$tags): void
    {
        $tags = Collection::make($tags);
        $response = Craft::$app->getResponse();
        $isWebResponse = $response instanceof \craft\web\Response;

        // Add any existing tags from the response headers
        if ($isWebResponse) {
            $existingTagsFromHeader = $response->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value, first: false) ?? [];
            $tags->push(...$existingTagsFromHeader);
            $response->getHeaders()->remove(HeaderEnum::CACHE_PURGE_TAG->value);
        }

        $tags = $this->prepareTags(...$tags);

        if ($tags->isEmpty()) {
            return;
        }

        Craft::info(new PsrMessage('Purging tags', [
            'tags' => $tags,
        ]), __METHOD__);

        if ($isWebResponse) {
            $tags->each(fn(StaticCacheTag $tag) => $response->getHeaders()->add(
                HeaderEnum::CACHE_PURGE_TAG->value,
                $tag->getValue(),
            ));

            return;
        }

        Helper::createGatewayApiClient()->request('POST', 'cache/purge', [
            // Mapping to string because: https://github.com/laravel/framework/pull/54630
            RequestOptions::JSON => [
                'tags' => $tags->map(fn(StaticCacheTag $tag) => (string) $tag)->values()->all(),
            ],
        ]);
    }

    public function purgeUrlPrefixes(string ...$urlPrefixes): void
    {
        $urlPrefixes = Collection::make($urlPrefixes)->filter()->unique();

        if ($urlPrefixes->isEmpty()) {
            return;
        }

        Craft::info(new PsrMessage('Purging URL prefixes', [
            'urlPrefixes' => $urlPrefixes->all(),
        ]), __METHOD__);

        Helper::createGatewayApiClient()->request('POST', 'cache/purge', [
            RequestOptions::JSON => [
                'prefixes' => $urlPrefixes->values()->all(),
            ],
        ]);
    }

    private function isCacheable(): bool
    {
        $request = Craft::$app->getRequest();
        $response = Craft::$app->getResponse();

        return
            ($request->getIsGet() || $request->getIsHead()) &&
            !$request->getIsCpRequest() &&
            $response instanceof \craft\web\Response &&
            $response->getIsOk();
    }

    private function staticCacheDecision(): array
    {
        $syncedNativeHeaders = $this->syncNativeCacheHeaders();
        $headers = Craft::$app->getResponse()->getHeaders();

        $cdnCacheControlDirectives = Collection::make($headers->get(
            HeaderEnum::CDN_CACHE_CONTROL->value,
            first: false,
        ) ?? []);

        if ($cdnCacheControlDirectives->isNotEmpty()) {
            return $this->createStaticCacheDecision(
                $cdnCacheControlDirectives,
                self::SOURCE_CDN_CACHE_CONTROL,
                $syncedNativeHeaders,
            );
        }

        $cacheControlDirectives = Collection::make($headers->get(
            HeaderEnum::CACHE_CONTROL->value,
            first: false,
        ) ?? []);

        if ($cacheControlDirectives->isNotEmpty()) {
            return $this->createStaticCacheDecision(
                $cacheControlDirectives,
                self::SOURCE_CACHE_CONTROL,
                $syncedNativeHeaders,
            );
        }

        $this->cacheDuration = $this->cacheDuration ?? Module::getInstance()->getConfig()->staticCacheDuration;
        $swrDuration = Module::getInstance()->getConfig()->staticCacheStaleWhileRevalidateDuration;

        return $this->createStaticCacheDecision(
            Collection::make([
                'public',
                "max-age=$this->cacheDuration",
                "stale-while-revalidate=$swrDuration",
            ]),
            self::SOURCE_DEFAULT,
            $syncedNativeHeaders,
        );
    }

    private function syncNativeCacheHeaders(): Collection
    {
        $headers = Craft::$app->getResponse()->getHeaders();
        $nativeHeaders = Collection::make($this->nativeCacheHeaders());
        $syncedNativeHeaders = Collection::make();

        foreach ([HeaderEnum::CDN_CACHE_CONTROL, HeaderEnum::CACHE_CONTROL] as $header) {
            $name = $header->value;

            if ($headers->has($name)) {
                continue;
            }

            $values = $nativeHeaders->first(fn(array $values, string $nativeName) => strtolower($nativeName) === strtolower($name));

            if (!$values) {
                continue;
            }

            $headers->set($name, $values);
            $syncedNativeHeaders->push($name);
        }

        return $syncedNativeHeaders;
    }

    private function createStaticCacheDecision(
        Collection $directives,
        string $source,
        Collection $syncedNativeHeaders,
    ): array {
        $responseHeaders = $this->responseCacheHeaders();
        $nativeHeaders = $this->nativeCacheHeaders();
        $hasSetCookie = isset($responseHeaders[HeaderEnum::SET_COOKIE->value]) ||
            isset($nativeHeaders[HeaderEnum::SET_COOKIE->value]);

        return [
            'source' => $source,
            'directives' => $directives,
            'blockers' => $this->cacheBlockers($directives, $hasSetCookie),
            'responseHeaders' => $responseHeaders,
            'nativeHeaders' => $nativeHeaders,
            'syncedNativeHeaders' => $syncedNativeHeaders->values()->all(),
            'hasSetCookie' => $hasSetCookie,
            'sessionStatus' => session_status(),
            'sessionCacheLimiter' => session_cache_limiter() ?: null,
        ];
    }

    private function responseCacheHeaders(): array
    {
        return $this->trackedHeaders(Collection::make(Craft::$app->getResponse()->getHeaders()));
    }

    private function nativeCacheHeaders(): array
    {
        return $this->trackedHeaders(Collection::make(GuzzleUtils::headersFromLines(headers_list())));
    }

    private function trackedHeaders(Collection $headers): array
    {
        $trackedHeaders = [];

        $headers->each(function(array $values, string $name) use (&$trackedHeaders) {
            $canonicalName = $this->canonicalHeaderName($name);

            if ($canonicalName === null) {
                return;
            }

            $trackedHeaders[$canonicalName] = Collection::wrap($trackedHeaders[$canonicalName] ?? [])
                ->merge($values)
                ->map(fn($value) => (string) $value)
                ->values()
                ->all();
        });

        return $trackedHeaders;
    }

    private function canonicalHeaderName(string $name): ?string
    {
        foreach (self::TRACKED_HEADERS as $trackedHeader) {
            if (strcasecmp($trackedHeader, $name) === 0) {
                return $trackedHeader;
            }
        }

        return null;
    }

    private function cacheBlockers(Collection $directives, bool $hasSetCookie): array
    {
        $blockers = $directives
            ->flatMap(fn(string $value) => preg_split('/\s*,\s*/', $value) ?: [])
            ->map(fn(string $value) => strtolower(trim($value)))
            ->filter(function(string $directive) {
                return in_array($directive, ['no-cache', 'no-store', 'private'], true) ||
                    in_array($directive, ['max-age=0', 's-maxage=0'], true);
            });

        if ($hasSetCookie) {
            $blockers->push('set-cookie');
        }

        return $blockers->unique()->values()->all();
    }

    private function prepareTags(string|StaticCacheTag ...$tags): Collection
    {
        return Collection::make($tags)
            ->map(fn(string|StaticCacheTag $tag) => is_string($tag) ? StaticCacheTag::create($tag) : $tag)
            ->filter(fn(StaticCacheTag $tag) => (bool) $tag->getValue())
            ->unique(fn(StaticCacheTag $tag) => $tag->getValue());
    }
}
