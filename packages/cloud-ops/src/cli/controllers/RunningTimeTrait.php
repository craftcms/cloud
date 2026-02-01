<?php

namespace craft\cloud\ops\cli\controllers;

trait RunningTimeTrait
{
    protected ?float $runningTime = null;

    public function beforeAction($action): bool
    {
        $this->runningTime = microtime(true);

        return parent::beforeAction($action);
    }

    public function afterAction($action, $result)
    {
        $this->runningTime = microtime(true) - $this->runningTime;

        $runningTime = round($this->runningTime, 2);
        $message = "`{$this->getRoute()}` completed in `{$runningTime}s`.";

        $this->stdout("\n");
        $this->stdout($this->markdownToAnsi($message));
        $this->stdout("\n");

        return parent::afterAction($action, $result);
    }
}
