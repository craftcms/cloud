<?php

namespace craft\cloud\fs;

use craft\base\TemporaryUploadUrlInterface as CraftTemporaryUploadUrlInterface;

// Craft added TemporaryUploadUrlInterface in 5.13.0; earlier versions get an empty interface.
if (interface_exists(CraftTemporaryUploadUrlInterface::class)) {
    interface TemporaryUploadUrlInterface extends CraftTemporaryUploadUrlInterface
    {
    }
} else {
    interface TemporaryUploadUrlInterface
    {
    }
}
