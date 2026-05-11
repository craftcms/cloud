<?php

declare(strict_types=1);

error_log('[craft-cloud] Composer autoload file loaded.');

$craftCloud = $_SERVER['CRAFT_CLOUD'] ?? null;
$craftCloudProjectId = $_SERVER['CRAFT_CLOUD_PROJECT_ID'] ?? null;
$craftCloudEnvironmentId = $_SERVER['CRAFT_CLOUD_ENVIRONMENT_ID'] ?? null;
$lambdaTaskRoot = $_SERVER['LAMBDA_TASK_ROOT'] ?? null;
$lambdaRuntimeApi = $_SERVER['AWS_LAMBDA_RUNTIME_API'] ?? null;

if ($craftCloud === null && $craftCloudProjectId !== null && $craftCloudEnvironmentId !== null) {
    $_SERVER['CRAFT_CLOUD'] = '1';
    $_ENV['CRAFT_CLOUD'] = '1';
    $craftCloud = '1';

    putenv('CRAFT_CLOUD=1');
}

if ($craftCloud !== null || $lambdaTaskRoot !== null || $lambdaRuntimeApi !== null) {
    $_SERVER['LOG_CHANNEL'] = 'stderr';
    $_SERVER['LOG_STACK'] = 'stderr';
    $_SERVER['LOG_STDERR_FORMATTER'] = 'default';
    $_ENV['LOG_CHANNEL'] = 'stderr';
    $_ENV['LOG_STACK'] = 'stderr';
    $_ENV['LOG_STDERR_FORMATTER'] = 'default';

    putenv('LOG_CHANNEL=stderr');
    putenv('LOG_STACK=stderr');
    putenv('LOG_STDERR_FORMATTER=default');

    error_log('[craft-cloud] Configured early Laravel log env for Craft Cloud.');
}
