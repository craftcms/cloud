<?php

namespace craft\cloud\runtime\event;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Exception;
use Illuminate\Support\Collection;
use Throwable;

class SqsHandler extends \Bref\Event\Sqs\SqsHandler
{
    public function handleSqs(SqsEvent $event, Context $context): void
    {
        $records = Collection::make($event->getRecords());

        if ($records->count() > 1) {
            throw new Exception('This handler does not support SQS batch processing.');
        }

        $record = $records->first();

        $body = json_decode(
            $record->getBody(),
            associative: false,
            flags: JSON_THROW_ON_ERROR
        );

        $jobId = $body->jobId ?? null;

        if (!$jobId) {
            throw new Exception('The SQS message does not contain a job ID.');
        }

        try {
            // TODO: bootstrap Craft and process the job directly, so we can get real exceptions.
            (new CliHandler())->handle([
                'command' => "cloud/queue/exec {$jobId}",
            ], $context, true);
        } catch (Throwable $e) {
            echo $e->getMessage();

            (new CliHandler())->handle([
                'command' => "cloud/queue/fail {$jobId} --message={$e->getMessage()}",
            ], $context, true);

            // Ensure record is marked as failed, regardless of the `ReportBatchItemFailures` setting.
            // Throwing instead of calling $this->markAsFailed, as we only support single-record batches.
            throw $e;
        }
    }
}
