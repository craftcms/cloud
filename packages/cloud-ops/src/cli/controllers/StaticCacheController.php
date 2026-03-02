<?php

namespace craft\cloud\ops\cli\controllers;

use craft\cloud\ops\Module;
use craft\console\Controller;
use yii\console\ExitCode;

class StaticCacheController extends Controller
{
    public function actionPurgePrefixes(string ...$prefixes): int
    {
        $this->do('Purging prefixes', function() use ($prefixes) {
            Module::instance()->getStaticCache()->purgeUrlPrefixes(...$prefixes);
        });

        return ExitCode::OK;
    }

    public function actionPurgeTags(string ...$tags): int
    {
        $this->do('Purging tags', function() use ($tags) {
            Module::instance()->getStaticCache()->purgeTags(...$tags);
        });

        return ExitCode::OK;
    }
}
