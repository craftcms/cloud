<?php

namespace craft\cloud\cli\controllers;

use Craft;
use craft\cloud\queue\TestJob;
use craft\queue\Queue;
use yii\console\ExitCode;
use yii\queue\ExecEvent;

class QueueController extends BaseController
{
    /**
     * The number of jobs to push to the queue
     */
    public int $count = 1;

    /**
     * The amount of time each job should sleep for
     */
    public int $seconds = 0;

    /**
     * Whether to run the job immediately
     */
    public bool $run = false;

    /**
     * Whether the job should throw an exception
     */
    public bool $throw = false;

    /**
     * The message to pass when manually failing a job
     */

    /**
     * The exception message:
     * - when self::$throw is `true`
     * - when manually failing a job
     */
    public ?string $message = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'push-test-job' => [
                'message',
                'run',
                'throw',
                'seconds',
                'count',
            ],
            'fail' => [
                'message',
            ],
            default => [],
        });
    }

    public function actionFail(string $jobId): int
    {
        $this->do("Failing job #$jobId", function() use ($jobId) {
            /** @var Queue $queue */
            $queue = Craft::$app->getQueue();
            $event = new ExecEvent([
                'id' => $jobId,
                'error' => $this->message ? new \yii\base\Exception($this->message) : null,

                // Prevent retry
                'attempt' => 1,
            ]);
            $queue->handleError($event);
        });

        return ExitCode::OK;
    }

    public function actionExec(string $jobId): int
    {
        $this->do("Executing job #$jobId", function() use ($jobId) {
            /** @var Queue $queue */
            $queue = Craft::$app->getQueue();
            $jobFound = $queue->executeJob($jobId);

            if (!$jobFound) {
                $this->stdout($this->markdownToAnsi("Job not found: `$jobId`"));
                $this->stdout("\n");
            }
        });

        return ExitCode::OK;
    }

    public function actionPushTestJob(): int
    {
        for ($i = 0; $i < $this->count; $i++) {
            $job = new TestJob([
                'message' => $this->message ?? '',
                'throw' => $this->throw,
                'seconds' => $this->seconds,
            ]);

            $this->do('Pushing test job', function() use ($job) {
                $jobId = Craft::$app->getQueue()->push($job);

                if ($this->run) {
                    $this->do('Running test job', fn() => $this->actionExec($jobId));
                }
            });
        }

        return ExitCode::OK;
    }
}
