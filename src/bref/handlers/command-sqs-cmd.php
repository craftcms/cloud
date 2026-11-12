<?php

declare(strict_types=1);

/*
 * Handler for Craft Cloud's command function. Its path is referenced by the Cloud infrastructure, so it must not move.
 */

require __DIR__ . '/../../../../../autoload.php';

return new \CraftCms\Cloud\Lambda\CommandSqsHandler();
