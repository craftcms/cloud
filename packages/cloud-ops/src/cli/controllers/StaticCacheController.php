<?php

namespace craft\cloud\ops\cli\controllers;

use craft\cloud\Plugin;
use craft\console\Controller;
use yii\console\ExitCode;

class StaticCacheController extends Controller
{
    public function actionPurgePrefixes(string ...$prefixes): int
    {
        $this->do('Purging prefixes', function() use ($prefixes) {
            Plugin::getInstance()->getStaticCache()->purgeUrlPrefixes(...$prefixes);
        });

        return ExitCode::OK;
    }

    public function actionPurgeTags(string ...$tags): int
    {
        $this->do('Purging tags', function() use ($tags) {
            Plugin::getInstance()->getStaticCache()->purgeTags(...$tags);
        });

        return ExitCode::OK;
    }
}
