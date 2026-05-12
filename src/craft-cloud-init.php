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

$taskRoot = $_SERVER['LAMBDA_TASK_ROOT'] ?? $_SERVER['CRAFT_BASE_PATH'] ?? '/var/task';
$loggingConfig = $taskRoot . '/config/logging.php';
$cachedConfig = $taskRoot . '/bootstrap/cache/config.php';

error_log(
    '[craft-cloud] Config diagnostics: '
        . json_encode([
            'task_root' => $taskRoot,
            'logging_config_exists' => is_file($loggingConfig),
            'logging_config_has_emergency_env' => is_file($loggingConfig)
                ? str_contains((string) file_get_contents($loggingConfig), 'LOG_EMERGENCY_PATH')
                : null,
            'cached_config_exists' => is_file($cachedConfig),
            'cached_config_has_storage_logs' => is_file($cachedConfig)
                ? str_contains((string) file_get_contents($cachedConfig), 'storage/logs')
                : null,
            'cached_config_has_php_stderr' => is_file($cachedConfig)
                ? str_contains((string) file_get_contents($cachedConfig), 'php://stderr')
                : null,
        ]),
);
