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
        $this->tagsToPurge->push(Plugin::getInstance()->getConfig()->environmentId);
    }

    public function purgeCdn(): void
    {
        $this->tagsToPurge->push(self::CDN_PREFIX . Plugin::getInstance()->getConfig()->environmentId);
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

        $environmentId = Plugin::getInstance()->getConfig()->environmentId;
        $this->tagsToPurge->prepend("$environmentId:$uri");
    }

    private function addCacheHeadersToWebResponse(): void
    {
        $this->cacheDuration = $this->cacheDuration ?? Plugin::getInstance()->getConfig()->staticCacheDuration;
        $headers = Craft::$app->getResponse()->getHeaders();

        $cacheControlDirectives = Collection::make($headers->get(
            HeaderEnum::CACHE_CONTROL->value,
            first: false,
        ));

        // Copy cache-control directives to the cdn-cache-control header
        // @see https://developers.cloudflare.com/cache/concepts/cdn-cache-control/#header-precedence
        $swrDuration = Plugin::getInstance()->getConfig()->staticCacheStaleWhileRevalidateDuration;
        $cdnCacheControlDirectives = $cacheControlDirectives->isEmpty()
            ? Collection::make([
                'public',
                "max-age=$this->cacheDuration",
                "stale-while-revalidate=$swrDuration",
            ])
            : $cacheControlDirectives;

        $headers->setDefault(
            HeaderEnum::CDN_CACHE_CONTROL->value,
            $cdnCacheControlDirectives->implode(','),
        );

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

        // TODO: make sure we don't go over max header size
        Helper::makeGatewayApiRequest([
            // Mapping to string because: https://github.com/laravel/framework/pull/54630
            HeaderEnum::CACHE_PURGE_TAG->value => $tags->map(fn(StaticCacheTag $tag) => (string) $tag)->implode(','),
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

        // TODO: make sure we don't go over max header size
        Helper::makeGatewayApiRequest([
            HeaderEnum::CACHE_PURGE_PREFIX->value => $urlPrefixes->implode(','),
        ]);
    }

    private function isCacheable(): bool
    {
        $response = Craft::$app->getResponse();

        return
            Craft::$app->getView()->templateMode === View::TEMPLATE_MODE_SITE &&
            $response instanceof \craft\web\Response &&
            $response->getIsOk();
    }

    private function prepareTags(string|StaticCacheTag ...$tags): Collection
    {
        return Collection::make($tags)
            ->map(fn(string|StaticCacheTag $tag) => is_string($tag) ? StaticCacheTag::create($tag) : $tag)
            ->filter(fn(StaticCacheTag $tag) => (bool) $tag->getValue())
            ->unique(fn(StaticCacheTag $tag) => $tag->getValue());
    }
}
