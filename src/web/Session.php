<?php

namespace craft\cloud\web;

use Craft;
use craft\helpers\App;
use samdark\log\PsrMessage;
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

        Craft::info(new PsrMessage(
            'Session opened',
            [
                'stack' => App::backtrace(8),
            ],
        ), __METHOD__);
    }

    public function close()
    {
        $wasActive = $this->getIsActive();

        parent::close();

        if (!$wasActive) {
            return;
        }

        Craft::info(new PsrMessage(
            'Session closed',
            [
                'stack' => App::backtrace(8),
            ],
        ), __METHOD__);
    }
}
