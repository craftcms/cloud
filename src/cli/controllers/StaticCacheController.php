<?php

namespace craft\cloud\cli\controllers;

use craft\cloud\Module;
use craft\console\Controller;
use yii\console\Exception;
use yii\console\ExitCode;

class StaticCacheController extends Controller
{
    public function actionPurgeAll(): int
    {
        $this->do('Purging static cache', function() {
            $module = Module::getInstance();
            $environmentId = $this->environmentId();
            $module->getStaticCache()->purgeTags(
                "$environmentId:uri",
                "$environmentId:cdn",
                "$environmentId:rasterize",
            );
        });

        return ExitCode::OK;
    }

    public function actionPurgeCdn(): int
    {
        $this->do('Purging CDN static cache', function() {
            $module = Module::getInstance();
            $environmentId = $this->environmentId();
            $module->getStaticCache()->purgeTags(
                "$environmentId:cdn",
                "$environmentId:rasterize",
            );
        });

        return ExitCode::OK;
    }

    public function actionPurgeOrigin(): int
    {
        $this->do('Purging origin static cache', function() {
            $module = Module::getInstance();
            $environmentId = $this->environmentId();
            $module->getStaticCache()->purgeTags("$environmentId:uri");
        });

        return ExitCode::OK;
    }

    public function actionPurgePrefixes(string ...$prefixes): int
    {
        $this->do('Purging prefixes', function() use ($prefixes) {
            Module::getInstance()->getStaticCache()->purgeUrlPrefixes(...$prefixes);
        });

        return ExitCode::OK;
    }

    public function actionPurgeTags(string ...$tags): int
    {
        $this->do('Purging tags', function() use ($tags) {
            Module::getInstance()->getStaticCache()->purgeTags(...$tags);
        });

        return ExitCode::OK;
    }

    private function environmentId(): string
    {
        $environmentId = Module::getInstance()->getConfig()->environmentId;
        if (!$environmentId) {
            throw new Exception('Static cache purges require an environment ID.');
        }

        return $environmentId;
    }
}
