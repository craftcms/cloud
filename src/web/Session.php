<?php

namespace craft\cloud\web;

use craft\cloud\Module;
use craft\helpers\App;
use yii\web\DbSession;

class Session extends DbSession
{
    public function open()
    {
        $wasActive = $this->getIsActive();

        parent::open();

        if ($wasActive || !$this->getIsActive()) {
            return;
        }

        Module::info('Session opened', [
            'stack' => App::backtrace(8),
        ]);
    }
}
