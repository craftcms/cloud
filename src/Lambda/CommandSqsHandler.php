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
     * Time kept back from the invocation's remaining time, so the command times out first and its result is still sent.
     */
    private const RESULT_BUFFER_SECONDS = 5;

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
                timeout: max(1, ($context->getRemainingTimeInMillis() / 1000) - self::RESULT_BUFFER_SECONDS),
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

    /**
     * Posts the result, falling back to posting the failure. It never throws, because the command has already run
     * and a failed SQS record would be delivered again.
     */
    private function sendResult(string $url, array $result): void
    {
        $error = $this->post($url, $result);

        if ($error === null) {
            return;
        }

        fwrite(STDERR, self::encode($result) . "\n");
        $error = $this->post($url, ['exit_code' => 255, 'output' => $error]);

        if ($error !== null) {
            fwrite(STDERR, $error . "\n");
        }
    }

    /**
     * @return string|null A description of the failure, or null if the callback accepted the body.
     */
    private function post(string $url, array $body): ?string
    {
        $project = Cloud::env('CRAFT_CLOUD_PROJECT_ID');
        $environment = Cloud::env('CRAFT_CLOUD_ENVIRONMENT_ID');

        try {
            $response = $this->client->post($url, [
                RequestOptions::BODY => self::encode($body),
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => "Craft/Cloud/{$project}/{$environment}",
                ],
            ]);
        } catch (GuzzleException $e) {
            return 'Request failed: ' . $e->getMessage();
        }

        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300
            ? null
            : "Failed to send command output: [{$status}] [{$response->getBody()}]";
    }

    /**
     * Command output can contain invalid UTF-8, which would otherwise make encoding fail and lose the output.
     */
    private static function encode(array $body): string
    {
        return json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
