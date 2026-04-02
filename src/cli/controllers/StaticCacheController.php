<?php

namespace craft\cloud\cli\controllers;

use craft\cloud\Module;
use craft\console\Controller;
use yii\console\ExitCode;

class StaticCacheController extends Controller
{
    public function actionPurgeTags(string ...$tags): int
    {
        $this->do('Purging tags', function() use ($tags) {
            Module::getInstance()->getStaticCache()->purgeTags(...$tags);
        });

        return ExitCode::OK;
    }
}
