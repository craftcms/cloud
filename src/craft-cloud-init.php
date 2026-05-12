<?php

declare(strict_types=1);

error_log('[craft-cloud] Composer autoload file loaded.');

$diagnosticKeys = [
    'AWS_LAMBDA_RUNTIME_API',
    'CRAFT_CLOUD',
    'CRAFT_CLOUD_PROJECT_ID',
    'CRAFT_CLOUD_ENVIRONMENT_ID',
    'LOG_CHANNEL',
    'LOG_STACK',
    'LOG_STDERR_FORMATTER',
    'LOG_EMERGENCY_PATH',
];

$diagnostics = [];

foreach ($diagnosticKeys as $key) {
    $serverValue = $_SERVER[$key] ?? null;
    $envValue = $_ENV[$key] ?? null;
    $getenvValue = getenv($key);

    $diagnostics[$key] = [
        'server' => $serverValue === null ? null : (string) $serverValue,
        'env' => $envValue === null ? null : (string) $envValue,
        'getenv' => $getenvValue === false ? null : $getenvValue,
    ];
}

error_log('[craft-cloud] Runtime diagnostics: ' . json_encode($diagnostics));
