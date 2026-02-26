<?php

namespace craft\cloud\ops\runtime\event;

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\FpmRuntime\FpmHandler;

class EventHandler implements Handler
{
    private FpmHandler $fpmHandler;

    public function __construct(FpmHandler $fpmHandler)
    {
        $this->fpmHandler = $fpmHandler;
    }

    public function handle(mixed $event, Context $context)
    {
        // is this a sqs event?
        if (isset($event['Records'])) {
            return (new SqsHandler())->handle($event, $context);
        }

        // is this a craft command event?
        if (isset($event['command'])) {
            return (new CliHandler())->handle($event, $context);
        }

        return $this->fpmHandler->handle($event, $context);
    }
}
