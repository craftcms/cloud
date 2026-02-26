<?php

namespace craft\cloud\bref\handlers;

use Bref\Context\Context;
use Bref\Event\Handler;
use craft\cloud\bref\craft\CraftCliEntrypoint;
use InvalidArgumentException;

/**
 * @internal
 */
final class CommandHandler implements Handler
{
    /**
     * @inheritDoc
     */
    public function handle(mixed $event, Context $context): array
    {
        if (!isset($event['command'])) {
            throw new InvalidArgumentException('No command found.');
        }

        $command = $event['command'];

        $entrypoint = new CraftCliEntrypoint();

        return $entrypoint->lambdaCommand($command, $context);
    }
}
