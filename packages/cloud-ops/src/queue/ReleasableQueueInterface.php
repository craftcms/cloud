<?php

namespace craft\cloud\queue;

use craft\queue\ReleasableQueueInterface as CraftReleasableQueueInterface;

if (interface_exists(CraftReleasableQueueInterface::class)) {
    interface ReleasableQueueInterface extends CraftReleasableQueueInterface
    {
    }
} else {
    interface ReleasableQueueInterface
    {
    }
}
