<?php

declare(strict_types=1);

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

// Until CMS #20545 is released, exercise its actual source, not substitute event/policy classes.
$source = getenv('CMS_SOURCE_PATH');
if ($source) {
    $loader->addPsr4('CraftCms\\Cms\\', $source . '/src', prepend: true);
}
