<?php

namespace craft\cloud\cli\controllers;

trait RunningTimeTrait
{
    protected ?float $runningTime = null;
    protected ?float $startTime = null;
    protected ?float $endTime = null;

    public function beforeAction($action): bool
    {
        $this->startTime = microtime(true);

        return parent::beforeAction($action);
    }

    public function afterAction($action, $result)
    {
        if ($this->startTime !== null) {
            $this->endTime = microtime(true);
            $this->runningTime = $this->endTime - $this->startTime;

            $runningTime = round($this->runningTime, 2);
            $message = "`{$this->getRoute()}` completed in `{$runningTime}s`.";

            $this->stdout("\n");
            $this->stdout($this->markdownToAnsi($message));
            $this->stdout("\n");
        }

        return parent::afterAction($action, $result);
    }
}
