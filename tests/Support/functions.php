<?php

declare(strict_types=1);

namespace craft\cloud;

use craft\cloud\tests\Support\DnsRecords;

function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
{
    return DnsRecords::get();
}
