<?php

declare(strict_types=1);

namespace CraftCms\Cloud;

/**
 * Reads the Craft Cloud function's environment.
 *
 * Read `CRAFT_CLOUD_*` variables through this class rather than `$_SERVER` or `env()`, so there's one place that
 * decides whether the app is on Craft Cloud and how an unset value looks. Values come from the process environment,
 * so they are correct even when Laravel's config is cached.
 */
final class Cloud
{
    public static function isCraftCloud(): bool
    {
        return self::env('CRAFT_CLOUD') !== null || self::env('AWS_LAMBDA_RUNTIME_API') !== null;
    }

    /**
     * Returns an environment variable's value, or `null` if it's unset or empty.
     */
    public static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
