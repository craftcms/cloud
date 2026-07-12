<?php

namespace craft\cloud;

use Craft;
use craft\base\ElementInterface;
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
use samdark\log\PsrMessage;
use yii\base\Event;
use yii\caching\TagDependency;
use yii\web\HeaderCollection;

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
 *   - `{environmentId}:overflow` (when the response has too many cache tags)
 */
class StaticCache extends \yii\base\Component
{
    public const CDN_PREFIX = 'cdn:';

    /**
     * Cloudflare's documented aggregate Cache-Tag value limit. It includes
     * commas and whitespace, but excludes the header field name.
     *
     * @see https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/#a-few-things-to-remember
     */
    private const MAX_HEADER_VALUE_LENGTH = 16 * 1024;
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

        $headers->setDefault(
            HeaderEnum::CDN_CACHE_CONTROL->value,
            $this->staticCacheDirectives()->implode(','),
        );

        $existingTagsFromHeader = Collection::make($headers->get(HeaderEnum::CACHE_TAG->value, first: false) ?? [])
            ->flatMap(fn(string $headerValue) => explode(',', $headerValue))
            ->map(fn(string $tag) => trim($tag))
            ->filter(fn(string $tag) => $tag !== '');
        $headers->remove(HeaderEnum::CACHE_TAG->value);
        $this->tags->push(...$existingTagsFromHeader);
        $this->tags = $this->normalizeCacheTags(...$this->tags);
        $cacheTags = $this->prepareCacheTagsForHeader($this->tags);

        Craft::info(new PsrMessage('Adding cache tags to response', [
            'tags' => $cacheTags,
        ]), __METHOD__);

        $this->setCacheTagHeader(HeaderEnum::CACHE_TAG->value, $headers, $cacheTags);
    }

    public function purgeTags(string|StaticCacheTag ...$tags): void
    {
        $tags = Collection::make($tags);
        $response = Craft::$app->getResponse();
        $isWebResponse = $response instanceof \craft\web\Response;

        // Add any existing tags from the response headers
        if ($isWebResponse) {
            $existingTagsFromHeader = Collection::make($response->getHeaders()->get(
                HeaderEnum::CACHE_PURGE_TAG->value,
                first: false,
            ) ?? [])
                ->flatMap(fn(string $headerValue) => explode(',', $headerValue))
                ->map(fn(string $tag) => trim($tag))
                ->filter(fn(string $tag) => $tag !== '');
            $tags->push(...$existingTagsFromHeader);
            $response->getHeaders()->remove(HeaderEnum::CACHE_PURGE_TAG->value);
        }

        $tags = $this->normalizeCacheTags(...$tags);

        if ($tags->isEmpty()) {
            return;
        }

        $tags = $this->normalizeCacheTags($this->overflowTag(), ...$tags);

        Craft::info(new PsrMessage('Purging tags', [
            'tags' => $tags,
        ]), __METHOD__);

        if ($isWebResponse) {
            $tags = $this->prepareCacheTagsForHeader($tags);

            $this->setCacheTagHeader(
                HeaderEnum::CACHE_PURGE_TAG->value,
                $response->getHeaders(),
                $tags,
            );

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

    private function setCacheTagHeader(string $header, HeaderCollection $headers, Collection $tags): void
    {
        if ($tags->isEmpty()) {
            return;
        }

        $headers->set($header, $tags->map(fn(StaticCacheTag $tag) => $tag->getValue())->implode(','));
    }

    private function prepareCacheTagsForHeader(Collection $tags): Collection
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

        Craft::info(new PsrMessage('Cache tags exceed the maximum header value length; using overflow tag', [
            'maxHeaderValueLength' => self::MAX_HEADER_VALUE_LENGTH,
            'truncatedTags' => $truncatedTagCount,
            'overflowTag' => $overflowTag->getValue(),
        ]), __METHOD__);

        return $headerTags;
    }

    private function truncateCacheTags(Collection $tags): Collection
    {
        $headerValueLength = 0;

        return $tags->filter(function(StaticCacheTag $tag) use (&$headerValueLength) {
            $value = $tag->getValue();
            $separatorLength = $headerValueLength === 0 ? 0 : 1;
            $newHeaderValueLength = $headerValueLength + $separatorLength + StringHelper::byteLength($value);

            if ($newHeaderValueLength > self::MAX_HEADER_VALUE_LENGTH) {
                return false;
            }

            $headerValueLength = $newHeaderValueLength;

            return true;
        });
    }

    private function overflowTag(): StaticCacheTag
    {
        $environmentId = Module::getInstance()->getConfig()->environmentId;

        return StaticCacheTag::create("{$environmentId}:overflow");
    }
}
