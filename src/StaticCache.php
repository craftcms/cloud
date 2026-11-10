<?php

declare(strict_types=1);

namespace craft\cloud;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects static cache purges for content changes, and sends them through the gateway once the request, command or
 * queue job is done.
 */
class StaticCache
{
    /** @var list<string> */
    private array $tagsToPurge = [];

    /** @var list<string> */
    private array $fetchUrls = [];

    public function __construct(
        private readonly CacheTags $tags,
        private readonly Gateway $gateway,
    ) {}

    public function handleElementCachesInvalidated(ElementCachesInvalidated $event): void
    {
        if ($event->element && ElementHelper::isDraftOrRevision($event->element)) {
            return;
        }

        array_push($this->tagsToPurge, ...array_map($this->tags->dependency(...), $event->tags));
    }

    public function handleElementSaved(ElementSaved $event): void
    {
        if (!$this->addUriPurge($event->element)) {
            return;
        }

        $url = $event->element->getUrl();

        if ($url !== null) {
            $this->fetchUrls[] = $url;
        }
    }

    public function handleElementDeleted(ElementDeleted $event): void
    {
        $this->addUriPurge($event->element);
    }

    /**
     * Sends the collected purges. Failures are logged rather than thrown, as the content change itself succeeded.
     */
    public function flush(): void
    {
        [$tags, $fetchUrls] = [$this->tagsToPurge, $this->fetchUrls];
        $this->tagsToPurge = [];
        $this->fetchUrls = [];

        try {
            $this->gateway->purge($tags, $fetchUrls);
        } catch (Throwable $exception) {
            Log::error('Failed to purge the Craft Cloud static cache.', [
                'exception' => $exception,
                'tags' => $tags,
                'fetchUrls' => $fetchUrls,
            ]);
        }
    }

    private function addUriPurge(ElementInterface $element): bool
    {
        // `uri` is a property of Craft's base element class, not part of the interface.
        $uri = property_exists($element, 'uri') ? $element->uri : null;
        $environmentId = config('cloud.environment_id');

        if (!$uri || !$environmentId || ElementHelper::isDraftOrRevision($element)) {
            return false;
        }

        $path = $element->getIsHomepage() ? '/' : '/' . trim($uri, characters: '/');
        $this->tagsToPurge[] = "{$environmentId}:uri:{$path}";

        return true;
    }
}
