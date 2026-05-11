<?php

declare(strict_types=1);

error_log('[craft-cloud] Composer autoload file loaded.');

$craftCloud = $_SERVER['CRAFT_CLOUD'] ?? null;
$lambdaTaskRoot = $_SERVER['LAMBDA_TASK_ROOT'] ?? null;
$lambdaRuntimeApi = $_SERVER['AWS_LAMBDA_RUNTIME_API'] ?? null;

if ($craftCloud !== null || $lambdaTaskRoot !== null || $lambdaRuntimeApi !== null) {
    $_SERVER['LOG_CHANNEL'] = 'stderr';
    $_SERVER['LOG_STACK'] = 'stderr';
    $_ENV['LOG_CHANNEL'] = 'stderr';
    $_ENV['LOG_STACK'] = 'stderr';

    putenv('LOG_CHANNEL=stderr');
    putenv('LOG_STACK=stderr');

    error_log('[craft-cloud] Configured early Laravel log env for Craft Cloud.');
}
