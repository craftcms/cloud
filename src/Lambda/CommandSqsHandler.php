<?php

declare(strict_types=1);

namespace CraftCms\Cloud\Lambda;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use CraftCms\Cloud\Cloud;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs Craft console commands sent by the Craft Cloud platform, and posts each command's output to its callback URL.
 *
 * Its Lambda function's handler is `src/bref/handlers/command-sqs-cmd.php`.
 *
 * @internal
 */
final class CommandSqsHandler extends SqsHandler
{
    /**
     * Lambda's 900-second limit, less a buffer so the command times out first and its result is still sent.
     */
    private const COMMAND_TIMEOUT_SECONDS = 900 - 5;

    public function __construct(
        private readonly Client $client = new Client(),
    ) {}

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        foreach ($event->getRecords() as $record) {
            $message = $record->getBody();
            $payload = json_decode($message, associative: true, flags: JSON_THROW_ON_ERROR);
            $callback = $payload['callback'] ?? throw new RuntimeException(
                "Callback URL not found. Message: [{$message}]",
            );
            $command = $payload['command'] ?? throw new RuntimeException('Command not found');

            $this->sendResult($callback, $this->runCommand($command, $context));
        }
    }

    /**
     * @return array{exit_code: int|null, output: string, duration?: float}
     */
    private function runCommand(string $command, Context $context): array
    {
        try {
            $process = Process::fromShellCommandline(
                '"${:PHP_BINARY}" "${:CRAFT_PATH}" ' . $command,
                env: [
                    'PHP_BINARY' => PHP_BINARY,
                    'CRAFT_PATH' => (Cloud::env('LAMBDA_TASK_ROOT') ?? '/var/task') . '/craft',
                    'LAMBDA_INVOCATION_CONTEXT' => json_encode($context, JSON_THROW_ON_ERROR),
                ],
                timeout: self::COMMAND_TIMEOUT_SECONDS,
            );

            $process->run(static function (string $type, string $buffer): void {
                echo $buffer;
            });

            return [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getErrorOutput() . $process->getOutput(),
                'duration' => microtime(true) - $process->getStartTime(),
            ];
        } catch (Throwable $e) {
            return [
                'exit_code' => 1,
                'output' => "Error running command [{$command}]: " . $e->getMessage(),
            ];
        }
    }

    private function sendResult(string $url, array $result): void
    {
        try {
            $response = $this->post($url, $result);
        } catch (GuzzleException $e) {
            fwrite(STDERR, json_encode($result) . "\n");
            $this->post($url, [
                'exit_code' => 255,
                'output' => 'Request failed: ' . $e->getMessage(),
            ]);

            return;
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        fwrite(STDERR, json_encode($result) . "\n");
        $this->post($url, [
            'exit_code' => 255,
            'output' => "Failed to send command output: [{$status}] [{$response->getBody()}]",
        ]);
    }

    private function post(string $url, array $body): \Psr\Http\Message\ResponseInterface
    {
        $project = Cloud::env('CRAFT_CLOUD_PROJECT_ID');
        $environment = Cloud::env('CRAFT_CLOUD_ENVIRONMENT_ID');

        return $this->client->post($url, [
            RequestOptions::JSON => $body,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::HEADERS => [
                'User-Agent' => "Craft/Cloud/{$project}/{$environment}",
            ],
        ]);
    }
}
