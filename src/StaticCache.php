<?php

namespace craft\cloud;

use Craft;
use craft\base\ElementInterface;
use craft\cloud\events\PurgeEvent;
use craft\cloud\queue\PurgeStaticCacheJob;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\services\Elements;
use craft\services\Gql;
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
 * Static Cache tags can appear in the `Cache-Tag` header.
 * The values are comma-separated and can be in several formats:
 *
 * - Added by the gateway:
 *   - `{environmentId}` (legacy)
 *   - `{environmentId}:{uri}` (legacy; non-homepage URI has a leading and no trailing slash)
 *   - `{environmentId}:uri`
 *   - `{environmentId}:uri:{uri}` (homepage URI is `/`, otherwise with a leading and no trailing slash)
 * - Added by the CDN:
 *   - `cdn:{environmentId}` (legacy)
 *   - `cdn:{environmentId}:{objectKey}` (legacy; object key has no leading slash)
 *   - `{environmentId}:cdn`
 *   - `{environmentId}:cdn:{objectKey}` (object key has no leading slash)
 *   - `{environmentId}:rasterize`
 *   - `{environmentId}:rasterize:{objectKey}` (object key has no leading slash)
 * - Added by Craft:
 *   - `{environmentShortId}{hashed}`
 *   - `{environmentId}:overflow` (when the response has too many tags or a selector is too long)
 */
class StaticCache extends \yii\base\Component
{
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
    public const MAX_TAG_VALUE_LENGTH = 1024;
    private const MAX_TAG_COUNT = 1000;
    private ?int $cacheDuration = null;
    private Collection $tags;
    private Collection $tagsToPurge;
    private Collection $fetchUrls;
    private bool $collectingCacheInfo = false;
    /** @var bool[] */
    private array $graphqlCachingStack = [];

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
            Gql::class,
            Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY,
            fn(Event $event) => $this->handleBeforeExecuteGqlQuery($event),
        );

        Event::on(
            Gql::class,
            Gql::EVENT_AFTER_EXECUTE_GQL_QUERY,
            fn(Event $event) => $this->handleAfterExecuteGqlQuery($event),
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
                    $this->sendPurgeTagsRequest(
                        $this->tagsToPurge,
                        $this->fetchUrls,
                    );
                } catch (\Throwable $e) {
                    Module::error(
                        'Failed to purge tags after request',
                        [
                            'exception' => $e,
                            'tags' => $this->tagsToPurge->all(),
                            'fetchUrls' => $this->fetchUrls->all(),
                        ],
                    );
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
        if ($this->graphqlCachingStack !== []) {
            Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = $this->graphqlCachingStack[0];
            $this->graphqlCachingStack = [];
        }

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

    private function handleBeforeExecuteGqlQuery(Event $event): void
    {
        if ($this->collectingCacheInfo) {
            // TODO: Remove after https://github.com/craftcms/cms/pull/19508 reaches Cloud's minimum supported Craft version.
            $generalConfig = Craft::$app->getConfig()->getGeneral();
            $this->graphqlCachingStack[] = $generalConfig->enableGraphqlCaching;
            $generalConfig->enableGraphqlCaching = false;
        }
    }

    private function handleAfterExecuteGqlQuery(Event $event): void
    {
        if ($this->graphqlCachingStack !== []) {
            Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = array_pop($this->graphqlCachingStack);
        }
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

        $this->addPurgeTags($tags->all());
    }

    private function handleRegisterCacheOptions(RegisterCacheOptionsEvent $event): void
    {
        $event->options[] = [
            'key' => 'craft-cloud-static-cache',
            'label' => Craft::t('app', 'Craft Cloud static cache'),
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
        $this->addPurgeTags(["$environmentId:uri"]);
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
        $environmentId = Module::getInstance()->getConfig()->environmentId;
        $this->addPurgeTags(["$environmentId:cdn", "$environmentId:rasterize"]);
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
        $tag = StaticCacheTag::create("$environmentId:uri:$uri");
        $this->addPurgeTags([$tag]);
        return true;
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
        $this->sendPurgeTagsRequest(Collection::make($tags));
    }

    /**
     * @param array<array-key, string|StaticCacheTag> $tags
     */
    public function addPurgeTags(array $tags): void
    {
        $this->tagsToPurge->push(...$this->normalizeCacheTags(...$tags));
    }

    private function sendPurgeTagsRequest(
        Collection $tags,
        ?Collection $fetchUrls = null,
    ): void {
        $tags = $this->normalizeCacheTags(...$tags);

        if ($tags->isEmpty()) {
            return;
        }

        $event = new PurgeEvent([
            'tags' => $tags->values()->all(),
        ]);
        $this->trigger(self::EVENT_BEFORE_PURGE, $event);

        $overflowTag = $this->overflowTag();
        $tags = $event->isValid
            ? $this->normalizeCacheTags(...$event->tags)
                ->map(fn(StaticCacheTag $tag) => StringHelper::byteLength($tag->getValue()) > self::MAX_TAG_VALUE_LENGTH ? $overflowTag : $tag)
                ->unique(fn(StaticCacheTag $tag) => $tag->getValue())
            : Collection::make();

        if ($tags->isEmpty()) {
            return;
        }

        $fetchUrls ??= Collection::make();
        $job = new PurgeStaticCacheJob([
            'tags' => $tags->map(fn(StaticCacheTag $tag) => (string) $tag)->values()->all(),
            'fetchUrls' => $fetchUrls
                ->unique()
                ->values()
                ->all(),
        ]);

        if (Craft::$app->getResponse() instanceof \craft\web\Response) {
            Craft::$app->getQueue()->push($job);
        } else {
            $job->execute(Craft::$app->getQueue());
        }
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
