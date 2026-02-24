<?php

namespace craft\cloud\ops\cli\controllers;

use craft\console\Controller;
use yii\console\Exception;
use yii\console\ExitCode;

abstract class BaseController extends Controller
{
    use RunningTimeTrait;

    public function mustRun(string $route, array $params = []): void
    {
        $exitCode = $this->run($route, $params);

        if ($exitCode !== ExitCode::OK) {
            throw new Exception("Exit code \"$exitCode\" returned from \"{$route}\"");
        }
    }
}
