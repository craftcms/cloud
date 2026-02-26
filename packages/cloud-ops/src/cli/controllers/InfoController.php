<?php

namespace craft\cloud\cli\controllers;

use Craft;
use craft\cloud\Plugin;
use craft\console\Controller;
use yii\console\ExitCode;

class InfoController extends Controller
{
    public function actionIndex(): int
    {
        $packageName = 'craftcms/cloud';
        $packageVersion = \Composer\InstalledVersions::getVersion($packageName);

        $this->table([
            'Extension',
            'App ID',
            'Environment ID',
            'Build ID',
        ], [
            [
                "$packageName:$packageVersion",
                Craft::$app->id,
                Plugin::getInstance()->getConfig()->environmentId,
                Plugin::getInstance()->getConfig()->buildId,
            ],
        ]);
        return ExitCode::OK;
    }

    public function actionPhpInfo(): int
    {
        ob_start();
        phpinfo(INFO_ALL);
        $phpInfoStr = ob_get_clean();

        $this->stdout($phpInfoStr);

        return ExitCode::OK;
    }
}
