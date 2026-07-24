<?php

namespace craft\cloud\events;

use craft\base\ElementInterface;
use craft\cloud\StaticCacheTag;
use craft\events\CancelableEvent;

class PurgeEvent extends CancelableEvent
{
    /**
     * @var StaticCacheTag[] The static cache tags being purged.
     */
    public array $tags = [];

    /**
     * @var ElementInterface[] The elements that caused the purge.
     */
    public array $elements = [];
}
