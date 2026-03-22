<?php

namespace craft\cloud\bref\craft;

use Bref\Context\Context;
use Symfony\Component\Process\Process;

final class CraftCliEntrypoint
{
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

    private function processTimeoutSeconds(Context $context): int
    {
        $timeout = (int) floor($context->getRemainingTimeInMillis() / 1000);

        return max(1, $timeout);
    }

    public function lambdaCommand(string $command, Context $context): array
    {
        $environment = $this->invocationContext($context);

        return $this->command($command, $environment, $this->processTimeoutSeconds($context));
    }

    public function craftJob(string $jobId, Context $context): array
    {
        $environment = $this->invocationContext($context);

        return $this->command("cloud/queue/exec $jobId", $environment, $this->processTimeoutSeconds($context));
    }

    private function invocationContext(Context $context): array
    {
        return ['LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR)];
    }
}
