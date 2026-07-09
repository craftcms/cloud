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
        if ($this->getIsActive()) {
            return;
        }

        parent::open();

        if (session_status() !== PHP_SESSION_ACTIVE) {
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
        if (!$this->getIsActive()) {
            return;
        }

        parent::close();

        if (session_status() === PHP_SESSION_ACTIVE) {
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
