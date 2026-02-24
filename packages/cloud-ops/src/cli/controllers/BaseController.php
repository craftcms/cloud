<?php

namespace craft\cloud\ops\cli\controllers;

use craft\console\Controller;
use yii\console\Exception;
use yii\console\ExitCode;

abstract class BaseController extends Controller
{
    use RunningTimeTrait;

    public function mustRun(string $route): void
    {
        $exitCode = $this->run($route);

        if ($exitCode !== ExitCode::OK) {
            throw new Exception("Exit code \"$exitCode\" returned from \"{$route}\"");
        }
    }
}
