<?php

declare(strict_types=1);

namespace craft\cloud\bref\handlers;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use craft\cloud\bref\craft\CraftCliEntrypoint;
use craft\cloud\bref\curl\CurlClient;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class CommandSqsHandler extends SqsHandler
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

            $callback = $payload['callback'] ?? throw new RuntimeException(
                "Callback URL not found. Message: [{$message}]",
            );

            $command = $payload['command'] ?? throw new RuntimeException('Command not found');

            $result = $this->runCommand($command, $context);

            $this->sendResultBack($callback, $result);
        }
    }

    public function runCommand(string $command, Context $context): array
    {
        try {
            return $this->entrypoint->lambdaCommand($command, $context);
        } catch (Throwable $t) {
            return [
                'exit_code' => 1,
                'output' => "Error running command [{$command}]: " . $t->getMessage(),
            ];
        }
    }

    private function sendResultBack(string $url, array $body): void
    {
        $client = new CurlClient();

        $body = $this->encodeResult($body);

        $response = $client->post($url, $body);

        if ($response->curlError) {
            fwrite(STDERR, $body . "\n");

            $body = $this->encodeResult([
                'exit_code' => 255,
                'output' => "cURL request failed: {$response->curlError}",
            ]);

            $client->post($url, $body);

            return;
        }

        if (!$response->successful()) {
            fwrite(STDERR, $body . "\n");

            $body = $this->encodeResult([
                'exit_code' => 255,
                'output' => "Failed to send command output: [{$response->statusCode}] [{$response->body}]",
            ]);

            $client->post($url, $body);

            return;
        }
    }

    private function encodeResult(array $body): string
    {
        return json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
