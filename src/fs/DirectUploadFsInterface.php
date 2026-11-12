<?php

namespace craft\cloud\fs;

use craft\base\DirectUploadFsInterface as CraftDirectUploadFsInterface;

// Craft added DirectUploadFsInterface in 5.13.0; earlier versions get an empty interface.
if (interface_exists(CraftDirectUploadFsInterface::class)) {
    interface DirectUploadFsInterface extends CraftDirectUploadFsInterface
    {
    }
} else {
    interface DirectUploadFsInterface
    {
    }
}
