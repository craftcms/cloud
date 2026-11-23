<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\HttpCache\HttpCacheManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues static cache purges for content changes on the `cloud` HTTP cache client, and sends them once the request,
 * command or queue job is done.
 *
 * Craft doesn't purge HTTP caches for element changes or flush their invalidators yet, so Cloud does both.
 */
class StaticCache
{
    public const string CLIENT = 'cloud';

    public function __construct(
        private readonly HttpCacheManager $httpCache,
    ) {}

    public function handleElementCachesInvalidated(ElementCachesInvalidated $event): void
    {
        if ($event->element && ElementHelper::isDraftOrRevision($event->element)) {
            return;
        }

        $this->httpCache->invalidator(self::CLIENT)->invalidateTags($event->tags);
    }

    public function handleElementSaved(ElementSaved $event): void
    {
        $url = $this->url($event->element);

        if ($url !== null) {
            $this->httpCache->invalidator(self::CLIENT)->refreshPath($url);
        }
    }

    public function handleElementDeleted(ElementDeleted $event): void
    {
        $url = $this->url($event->element);

        if ($url !== null) {
            $this->httpCache->invalidator(self::CLIENT)->invalidatePath($url);
        }
    }

    /**
     * Sends the queued purges. Failures are logged rather than thrown, as the content change itself succeeded.
     */
    public function flush(): void
    {
        try {
            $this->httpCache->invalidator(self::CLIENT)->flush();
        } catch (Throwable $exception) {
            Log::error('Failed to purge the Craft Cloud static cache.', ['exception' => $exception]);
        }
    }

    private function url(ElementInterface $element): ?string
    {
        return ElementHelper::isDraftOrRevision($element) ? null : $element->getUrl();
    }
}
