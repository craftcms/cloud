<?php

namespace craft\cloud\ops\cli\controllers;

use Craft;
use craft\console\Controller;
use craft\events\CancelableEvent;
use yii\console\Exception;
use yii\console\ExitCode;

class UpController extends Controller
{
    public const EVENT_BEFORE_UP = 'beforeUp';
    public const EVENT_AFTER_UP = 'afterUp';

    public function actionIndex(): int
    {
        $event = new CancelableEvent();
        $this->trigger(self::EVENT_BEFORE_UP, $event);

        if (!$event->isValid) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->mustRun('/setup/php-session-table');
        $this->mustRun('/setup/db-cache-table');

        if (Craft::$app->getIsInstalled()) {
            $this->mustRun('/up');
            $this->purgeStaticCache();
        }

        $event = new CancelableEvent();
        $this->trigger(self::EVENT_AFTER_UP, $event);

        if (!$event->isValid) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function mustRun(string $route): void
    {
        $exitCode = $this->run($route);

        if ($exitCode !== ExitCode::OK) {
            throw new Exception("Exit code \"$exitCode\" returned from \"{$route}\"");
        }
    }

    private function purgeStaticCache(): void
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('cloud');
        if ($plugin && method_exists($plugin, 'getStaticCache')) {
            $plugin->getStaticCache()->purgeGateway();
        }
    }
}
