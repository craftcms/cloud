<?php

namespace craft\cloud;

use Craft;
use craft\base\ElementInterface;
use craft\cloud\events\PurgeEvent;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\services\Elements;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use craft\web\View;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Support\Collection;
use League\Uri\Components\Path;
use yii\base\Event;
use yii\caching\TagDependency;

/**
 * Static Cache tags can appear in the `Cache-Tag` and `Cache-Purge-Tag` headers.
 * The values are comma-separated and can be in several formats:
 *
 * - Added by the gateway:
 *   - `{environmentId}` (legacy)
 *   - `{environmentId}:{uri}` (legacy; URI has a leading and no trailing slash)
 *   - `origin:{environmentId}`
 *   - `origin:{environmentId}:{uri}` (URI has a leading and no trailing slash)
 * - Added by the CDN:
 *    - `cdn:{environmentId}`
 *    - `cdn:{environmentId}:{objectKey}` (object key has no leading slash)
 * - Added by Craft:
 *   - `{environmentShortId}{hashed}`
 *   - `{environmentId}:overflow` (when the response has too many cache tags)
 */
class StaticCache extends \yii\base\Component
{
    public const CDN_PREFIX = 'cdn:';

    /**
     * @event PurgeEvent The event that is triggered before static cache tags are purged.
     */
    public const EVENT_BEFORE_PURGE = 'beforePurge';

    /**
     * Cloudflare's documented Cache-Tag limits.
     *
     * @see https://developers.cloudflare.com/workers/cache/configuration/
     */
    private const MAX_TAG_HEADER_VALUE_LENGTH = 16 * 1024;
    private const MAX_TAG_VALUE_LENGTH = 1024;
    private const MAX_TAG_COUNT = 1000;
    private ?int $cacheDuration = null;
    private Collection $tags;
    private Collection $tagsToPurge;
    private Collection $fetchUrls;
    private bool $collectingCacheInfo = false;

    public function init(): void
    {
        $this->tags = Collection::make();
        $this->tagsToPurge = Collection::make();
        $this->fetchUrls = Collection::make();
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
                    $this->sendTagPurgeRequest(
                        $this->tagsToPurge,
                        $this->fetchUrls,
                    );
                } catch (\Throwable $e) {
                    Module::error('Failed to purge tags after request', [
                        'exceptionMessage' => $e->getMessage(),
                    ]);
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

        // InvalidateElementCachesEvent::$element was added in Craft 4.10/5.2:
        // https://github.com/craftcms/cms/pull/14950
        if (property_exists($event, 'element')) {
            $element = $event->element;
            $skip = $element && ElementHelper::isDraftOrRevision($element);
        } else {
            $element = null;
            $skip = $tags->contains(function(StaticCacheTag $tag) {
                return preg_match('/element::craft\\\\elements\\\\\S+::(drafts|revisions)/', $tag->originalValue);
            });
        }

        if ($skip) {
            return;
        }

        $tags = $this->beforePurge($element, ...$tags);
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
        if (!$this->purgeElementUri($event->element)) {
            return;
        }

        $url = $event->element->getUrl();

        if ($url !== null) {
            $this->fetchUrls->push($url);
        }
    }

    private function handleDeleteElement(ElementEvent $event): void
    {
        $this->purgeElementUri($event->element);
    }

    public function purgeAll(): void
    {
        $this->purgeOrigin();
        $this->purgeCdn();
    }

    public function purgeOrigin(): void
    {
        $environmentId = Module::getInstance()->getConfig()->environmentId;
        // Purge the legacy unnamespaced tag alongside the origin tag.
        $tags = $this->beforePurge(
            null,
            $environmentId,
            "origin:$environmentId",
        );
        $this->tagsToPurge->push(...$tags);
    }

    /**
     * @deprecated in 3.5.0. Use [[purgeOrigin()]] instead.
     */
    public function purgeGateway(): void
    {
        Craft::$app->getDeprecator()->log(
            __METHOD__,
            '`purgeGateway()` has been deprecated. Use `purgeOrigin()` instead.',
        );
        $this->purgeOrigin();
    }

    public function purgeCdn(): void
    {
        $tags = $this->beforePurge(
            null,
            self::CDN_PREFIX . Module::getInstance()->getConfig()->environmentId,
        );
        $this->tagsToPurge->push(...$tags);
    }

    private function purgeElementUri(ElementInterface $element): bool
    {
        $uri = $element->uri ?? null;

        if (ElementHelper::isDraftOrRevision($element) || !$uri) {
            return false;
        }

        $uri = $element->getIsHomepage()
            ? '/'
            : Path::new($uri)->withLeadingSlash()->withoutTrailingSlash();

        $environmentId = Module::getInstance()->getConfig()->environmentId;
        // Purge the legacy unnamespaced URI tag alongside the origin tag.
        $tags = $this->beforePurge($element, "$environmentId:$uri", "origin:$environmentId:$uri");
        $this->tagsToPurge = $tags->concat($this->tagsToPurge);

        return $tags->isNotEmpty();
    }

    private function addCacheHeadersToWebResponse(): void
    {
        $headers = Craft::$app->getResponse()->getHeaders();

        $headers->setDefault(
            HeaderEnum::CDN_CACHE_CONTROL->value,
            $this->staticCacheDirectives()->implode(','),
        );

        $this->tags->push(...$this->parseCacheTagsFromHeader(HeaderEnum::CACHE_TAG->value));
        $headers->remove(HeaderEnum::CACHE_TAG->value);
        $this->tags = $this->normalizeCacheTags(...$this->tags);
        $cacheTags = $this->truncateCacheTagsForHeader($this->tags);

        Module::info('Adding cache tags to response', [
            'tags' => $cacheTags,
        ]);

        $this->setCacheTagHeader(HeaderEnum::CACHE_TAG->value, $cacheTags);
    }

    public function purgeTags(string|StaticCacheTag ...$tags): void
    {
        $tags = Collection::make($tags);
        $response = Craft::$app->getResponse();

        if ($response instanceof \craft\web\Response) {
            $tags->push(...$this->parseCacheTagsFromHeader(HeaderEnum::CACHE_PURGE_TAG->value));
            $response->getHeaders()->remove(HeaderEnum::CACHE_PURGE_TAG->value);
        }

        $tags = $this->beforePurge(null, ...$tags);
        $this->sendTagPurgeRequest($tags);
    }

    private function sendTagPurgeRequest(Collection $tags, ?Collection $fetchUrls = null): void
    {
        $response = Craft::$app->getResponse();
        $isWebResponse = $response instanceof \craft\web\Response;
        $sendApiRequest = !$isWebResponse || $fetchUrls?->isNotEmpty();

        // Add any existing tags from the response headers
        if ($isWebResponse) {
            $headerTags = $this->parseCacheTagsFromHeader(HeaderEnum::CACHE_PURGE_TAG->value);
            $response->getHeaders()->remove(HeaderEnum::CACHE_PURGE_TAG->value);
            $tags->push(...$this->beforePurge(null, ...$headerTags));
        }

        $tags = $this->normalizeCacheTags(...$tags);

        if ($tags->isEmpty()) {
            return;
        }

        if (!$sendApiRequest) {
            $tags = $this->truncateCacheTagsForHeader($tags);

            Module::info('Purging tags', [
                'tags' => $tags,
            ]);

            $this->setCacheTagHeader(
                HeaderEnum::CACHE_PURGE_TAG->value,
                $tags,
            );

            return;
        }

        Module::info('Purging tags', [
            'tags' => $tags,
            'fetchUrls' => $fetchUrls,
        ]);

        $payload = [
            'tags' => $tags->map(fn(StaticCacheTag $tag) => (string) $tag)->values()->all(),
        ];

        if ($fetchUrls?->isNotEmpty()) {
            $payload['fetchUrls'] = $fetchUrls
                ->unique()
                ->values()
                ->all();
        }

        try {
            Helper::createGatewayApiClient()->request('POST', 'cache/purge', [
                RequestOptions::JSON => $payload,
                RequestOptions::TIMEOUT => 3,
            ]);
        } catch (\Throwable $e) {
            if ($isWebResponse) {
                $this->setCacheTagHeader(
                    HeaderEnum::CACHE_PURGE_TAG->value,
                    $this->truncateCacheTagsForHeader($tags),
                );
            }

            throw $e;
        }
    }

    private function beforePurge(
        ?ElementInterface $element,
        string|StaticCacheTag ...$tags,
    ): Collection {
        $tags = $this->normalizeCacheTags(...$tags);

        if ($tags->isEmpty()) {
            return $tags;
        }

        $event = new PurgeEvent([
            'tags' => $tags->values()->all(),
            'element' => $element,
        ]);

        $this->trigger(self::EVENT_BEFORE_PURGE, $event);

        if (!$event->isValid) {
            return Collection::make();
        }

        return Collection::make($event->tags);
    }

    public function purgeUrlPrefixes(string ...$urlPrefixes): void
    {
        $urlPrefixes = Collection::make($urlPrefixes)->filter()->unique();

        if ($urlPrefixes->isEmpty()) {
            return;
        }

        Module::info('Purging URL prefixes', [
            'urlPrefixes' => $urlPrefixes->all(),
        ]);

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

    private function staticCacheDirectives(): Collection
    {
        $this->syncNativeCacheHeaders();
        $headers = Craft::$app->getResponse()->getHeaders();

        $cdnCacheControlDirectives = Collection::make($headers->get(
            HeaderEnum::CDN_CACHE_CONTROL->value,
            first: false,
        ) ?? []);

        if ($cdnCacheControlDirectives->isNotEmpty()) {
            return $cdnCacheControlDirectives;
        }

        $cacheControlDirectives = Collection::make($headers->get(
            HeaderEnum::CACHE_CONTROL->value,
            first: false,
        ) ?? []);

        if ($cacheControlDirectives->isNotEmpty()) {
            return $cacheControlDirectives;
        }

        $this->cacheDuration = $this->cacheDuration ?? Module::getInstance()->getConfig()->staticCacheDuration;
        $swrDuration = Module::getInstance()->getConfig()->staticCacheStaleWhileRevalidateDuration;

        return Collection::make([
            'public',
            "max-age=$this->cacheDuration",
            "stale-while-revalidate=$swrDuration",
        ]);
    }

    private function syncNativeCacheHeaders(): void
    {
        $headers = Craft::$app->getResponse()->getHeaders();
        $nativeHeaders = Collection::make(GuzzleUtils::headersFromLines(headers_list()));

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
        }
    }

    private function normalizeCacheTags(string|StaticCacheTag ...$tags): Collection
    {
        return Collection::make($tags)
            ->map(fn(string|StaticCacheTag $tag) => is_string($tag) ? StaticCacheTag::create($tag) : $tag)
            ->filter(fn(StaticCacheTag $tag) => $tag->getValue() !== '')
            ->unique(fn(StaticCacheTag $tag) => $tag->getValue());
    }

    private function parseCacheTagsFromHeader(string $header): Collection
    {
        return Collection::make(Craft::$app->getResponse()->getHeaders()->get($header, first: false) ?? [])
            ->flatMap(fn(string $headerValue) => explode(',', $headerValue))
            ->map(fn(string $tag) => trim($tag))
            ->filter(fn(string $tag) => $tag !== '');
    }

    private function setCacheTagHeader(string $header, Collection $tags): void
    {
        if ($tags->isEmpty()) {
            return;
        }

        Craft::$app->getResponse()->getHeaders()->set(
            $header,
            $tags->map(fn(StaticCacheTag $tag) => $tag->getValue())->implode(','),
        );
    }

    private function truncateCacheTagsForHeader(Collection $tags): Collection
    {
        $headerTags = $this->truncateCacheTags($tags);

        if ($headerTags->count() === $tags->count()) {
            return $headerTags;
        }

        $overflowTag = $this->overflowTag();
        $headerTags = $this->truncateCacheTags(
            $this->normalizeCacheTags($overflowTag, ...$tags),
        );

        $inputTagCount = $tags
            ->reject(fn(StaticCacheTag $tag) => $tag->getValue() === $overflowTag->getValue())
            ->count();
        $headerTagCount = $headerTags
            ->reject(fn(StaticCacheTag $tag) => $tag->getValue() === $overflowTag->getValue())
            ->count();
        $truncatedTagCount = $inputTagCount - $headerTagCount;

        Module::info('Cache tags exceed header limits; using overflow tag', [
            'maxTagHeaderValueLength' => self::MAX_TAG_HEADER_VALUE_LENGTH,
            'maxTagCount' => self::MAX_TAG_COUNT,
            'maxTagValueLength' => self::MAX_TAG_VALUE_LENGTH,
            'truncatedTagCount' => $truncatedTagCount,
            'overflowTag' => $overflowTag->getValue(),
        ]);

        return $headerTags;
    }

    private function truncateCacheTags(Collection $tags): Collection
    {
        $headerValueLength = 0;
        $headerTags = Collection::make();

        /** @var StaticCacheTag $tag */
        foreach ($tags as $tag) {
            $value = $tag->getValue();
            $valueLength = StringHelper::byteLength($value);

            if (
                $headerTags->count() === self::MAX_TAG_COUNT ||
                $valueLength > self::MAX_TAG_VALUE_LENGTH
            ) {
                break;
            }

            $separatorLength = $headerValueLength === 0 ? 0 : 1;
            $newHeaderValueLength = $headerValueLength + $separatorLength + $valueLength;

            if ($newHeaderValueLength > self::MAX_TAG_HEADER_VALUE_LENGTH) {
                break;
            }

            $headerValueLength = $newHeaderValueLength;
            $headerTags->push($tag);
        }

        return $headerTags;
    }

    private function overflowTag(): StaticCacheTag
    {
        $environmentId = Module::getInstance()->getConfig()->environmentId;

        return StaticCacheTag::create("{$environmentId}:overflow");
    }
}
