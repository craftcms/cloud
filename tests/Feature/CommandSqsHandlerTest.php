<?php

declare(strict_types=1);

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use CraftCms\Cloud\Lambda\CommandSqsHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Runs the handler with a fake `craft` script in a temporary task root and returns the callback requests it sent.
 *
 * @param list<Response> $responses
 * @return list<array{url: string, body: array, userAgent: string}>
 */
function run_command_handler(string $command, array $responses, string $script): array
{
    $root = sys_get_temp_dir() . '/cloud-task-' . bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents("{$root}/craft", $script);
    test()->boot([
        'LAMBDA_TASK_ROOT' => $root,
        'CRAFT_CLOUD_PROJECT_ID' => 'project',
        'CRAFT_CLOUD_ENVIRONMENT_ID' => 'environment',
    ]);

    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $event = new SqsEvent([
        'Records' => [[
            'eventSource' => 'aws:sqs',
            'messageId' => '1',
            'body' => json_encode(['callback' => 'https://api.test/callback', 'command' => $command]),
        ]],
    ]);

    ob_start();
    new CommandSqsHandler(new Client(['handler' => $stack]))->handleSqs(
        $event,
        new Context('request', 0, 'arn', 'trace'),
    );
    ob_end_clean();

    unlink("{$root}/craft");
    rmdir($root);

    return array_map(static fn(array $entry): array => [
        'url' => (string) $entry['request']->getUri(),
        'body' => json_decode((string) $entry['request']->getBody(), associative: true),
        'userAgent' => $entry['request']->getHeaderLine('User-Agent'),
    ], $history);
}

it('runs the command and posts its exit code and output to the callback', function (): void {
    $requests = run_command_handler(
        "up --interactive=0 'quoted arg'",
        [new Response(200)],
        script: '<?php echo implode("|", array_slice($argv, 1)); exit(3);',
    );

    expect($requests)
        ->toHaveCount(1)
        ->and($requests[0]['url'])
        ->toBe('https://api.test/callback')
        ->and($requests[0]['userAgent'])
        ->toBe('Craft/Cloud/project/environment')
        ->and($requests[0]['body']['exit_code'])
        ->toBe(3)
        ->and($requests[0]['body']['output'])
        ->toBe('up|--interactive=0|quoted arg');
});

it('reports a failed callback to the callback URL', function (): void {
    $requests = run_command_handler(
        'help',
        [new Response(500, body: 'nope'), new Response(200)],
        script: '<?php echo "ok";',
    );

    expect($requests)
        ->toHaveCount(2)
        ->and($requests[1]['body'])
        ->toBe([
            'exit_code' => 255,
            'output' => 'Failed to send command output: [500] [nope]',
        ]);
});
