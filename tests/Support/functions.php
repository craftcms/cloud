<?php

declare(strict_types=1);

namespace CraftCms\Cloud;

use CraftCms\Cloud\Tests\Support\DnsRecords;

/**
 * Shadows PHP's `dns_get_record()` for unqualified calls in the `CraftCms\Cloud` namespace.
 */
function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
{
    return DnsRecords::get();
}
