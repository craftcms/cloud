<?php

namespace craft\cloud\ops\runtime\event;

use Bref\Context\Context;
use Bref\Event\Handler;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use yii\base\Exception;

class CliHandler implements Handler
{
    public ?Process $process = null;
    protected string $scriptPath = '/var/task/craft';
    protected ?float $totalRunningTime = null;
    public const MAX_EXECUTION_SECONDS = 900 - 3;

    /**
     * @inheritDoc
     */
    public function handle(mixed $event, Context $context, $throw = false): array
    {
        $commandArgs = $event['command'] ?? null;

        if (!$commandArgs) {
            throw new \Exception('No command found.');
        }

        $php = PHP_BINARY;
        $command = escapeshellcmd("{$php} {$this->scriptPath} {$commandArgs}");
        $remainingSeconds = $context->getRemainingTimeInMillis() / 1000;
        $timeout = max(1, $remainingSeconds - 1);
        $this->process = Process::fromShellCommandline($command, null, [
            'LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR),
        ], null, $timeout);

        try {
            /** @throws ProcessTimedOutException|ProcessFailedException */
            $this->process->mustRun(function($type, $buffer): void {
                echo $buffer;
            });
        } catch (\Throwable $e) {
            if ($throw) {
                throw $e;
            }
        }

        return [
            'exitCode' => $this->process->getExitCode(),
            'output' => $this->process->getErrorOutput() . $this->process->getOutput(),
            'runningTime' => $this->getTotalRunningTime(),
        ];
    }

    public function getTotalRunningTime(): float
    {
        if ($this->totalRunningTime !== null) {
            return $this->totalRunningTime;
        }

        if (!$this->process) {
            throw new Exception('Process does not exist');
        }

        return max(0, microtime(true) - $this->process->getStartTime());
    }
}
