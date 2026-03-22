<?php

namespace craft\cloud\bref\craft;

use Bref\Context\Context;
use Symfony\Component\Process\Process;

final class CraftCliEntrypoint
{
    /**
     * We leave a 10-second buffer for Lambda to start up and shutdown gracefully.
     *
     * TODO: use this value to set max_execution_time, queue ttr:
     * - @see EventHandler::MAX_SECONDS, EventHandler::MAX_HTTP_SECONDS
     * - @see SqsQueue::ttr(), AppConfig::getQueue()
     */
    public const MAX_SECONDS = 900 - 10;

    private function command(string $command, array $environment, int $timeout): array
    {
        $php = PHP_BINARY;

        $shellCommand = escapeshellcmd("$php /var/task/craft $command");

        $process = Process::fromShellCommandline($shellCommand, null, $environment, null, $timeout);

        $process->run(function($type, $buffer): void {
            echo $buffer;
        });

        return [
            'exit_code' => $process->getExitCode(),
            'output' => $process->getErrorOutput() . $process->getOutput(),
            'duration' => microtime(true) - $process->getStartTime(),
        ];
    }

    public function lambdaCommand(string $command, Context $context): array
    {
        $environment = $this->invocationContext($context);

        return $this->command($command, $environment, self::MAX_SECONDS);
    }

    public function craftJob(string $jobId, Context $context): array
    {
        $environment = $this->invocationContext($context);

        return $this->command("cloud/queue/exec $jobId", $environment, self::MAX_SECONDS);
    }

    private function invocationContext(Context $context): array
    {
        return ['LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR)];
    }
}
