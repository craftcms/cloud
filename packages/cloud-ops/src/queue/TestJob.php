<?php

namespace craft\cloud\ops\queue;

use craft\helpers\Console;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use yii\console\Exception;

class TestJob extends BaseJob
{
    public string $message = '';
    public bool $throw = false;
    public int $seconds = 0;

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('app', 'Test Job');
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        Console::stdout('Test job started.');

        if ($this->seconds) {
            Console::stdout("Sleeping for {$this->seconds} seconds…");
            sleep($this->seconds);
        }

        if ($this->throw) {
            Console::stdout('Throwing exception…');
            throw new Exception($this->message);
        }

        Console::stdout('Test job completed.');
    }
}
