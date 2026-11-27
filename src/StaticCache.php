<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\HttpCache\HttpCacheManager;

/**
 * Queues purges of saved and deleted elements' URLs on the `cloud` HTTP cache client. Saved elements' URLs are fetched
 * again, so the next visitor gets a cached page.
 *
 * The `craftcms/http-cache` package invalidates element tags and sends the queued invalidations once the request,
 * command or queue job is done.
 */
class StaticCache
{
    public const string CLIENT = 'cloud';

    public function __construct(
        private readonly HttpCacheManager $httpCache,
    ) {}

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

    private function url(ElementInterface $element): ?string
    {
        return ElementHelper::isDraftOrRevision($element) ? null : $element->getUrl();
    }
}
