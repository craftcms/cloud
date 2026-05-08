<?php

declare(strict_types=1);

namespace craft\cloud\tests\Support;

class DnsRecords
{
    private static array|false $records = false;

    public static function fake(array|false $records): void
    {
        self::$records = $records;
    }

    public static function get(): array|false
    {
        return self::$records;
    }

    public static function reset(): void
    {
        self::$records = false;
    }
}
