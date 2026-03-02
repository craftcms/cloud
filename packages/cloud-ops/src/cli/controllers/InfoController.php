<?php

namespace craft\cloud\ops\cli\controllers;

use Craft;
use craft\cloud\ops\Module;
use craft\console\Controller;
use yii\console\ExitCode;

class InfoController extends Controller
{
    public function actionIndex(): int
    {
        $packageName = 'craftcms/cloud-ops';
        $packageVersion = \Composer\InstalledVersions::getPrettyVersion($packageName) ?? 'unknown';

        $this->table([
            'Extension',
            'App ID',
            'Environment ID',
            'Build ID',
        ], [
            [
                "$packageName:$packageVersion",
                Craft::$app->id,
                Module::instance()->getConfig()->environmentId,
                Module::instance()->getConfig()->buildId,
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
