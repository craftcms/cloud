<?php

namespace craft\cloud\ops\bref\handlers;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use craft\cloud\ops\bref\craft\CraftCliEntrypoint;
use RuntimeException;

/**
 * @internal
 */
final class JobSqsHandler extends SqsHandler
{
    private CraftCliEntrypoint $entrypoint;

    public function __construct()
    {
        $this->entrypoint = new CraftCliEntrypoint();
    }

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        foreach ($event->getRecords() as $record) {
            $message = $record->getBody();

            $payload = json_decode($message, associative: true, flags: JSON_THROW_ON_ERROR);

            $jobId = $payload['jobId'] ?? throw new RuntimeException("Job ID not found. Message: [$message]");

            $this->entrypoint->craftJob($jobId, $context);
        }
    }
}
