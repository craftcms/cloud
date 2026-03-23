<?php

namespace craft\cloud\bref\craft;

use Bref\Context\Context;
use Symfony\Component\Process\Process;

final class CraftCliEntrypoint
{
    /**
     * Include buffer so process times out before PHP and Lambda.
     * @see \craft\cloud\Module::MAX_EXECUTION_SECONDS_CLI
     */
    private const PROCESS_TIMEOUT_SECONDS = 900 - 5;

    private function command(string $command, array $environment, int $timeout): array
    {
        $php = PHP_BINARY;

        $shellCommand = escapeshellcmd("$php /var/task/craft $command");

        $process = Process::fromShellCommandline($shellCommand, null, $environment, null, self::PROCESS_TIMEOUT_SECONDS);

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

        return $this->command($command, $environment, self::PROCESS_TIMEOUT_SECONDS);
    }

    public function craftJob(string $jobId, Context $context): array
    {
        $environment = $this->invocationContext($context);

        return $this->command("cloud/queue/exec $jobId", $environment, self::PROCESS_TIMEOUT_SECONDS);
    }

    private function invocationContext(Context $context): array
    {
        return ['LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR)];
    }
}
