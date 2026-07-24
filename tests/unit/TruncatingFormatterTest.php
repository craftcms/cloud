<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\TruncatingFormatter;
use Monolog\Formatter\FormatterInterface;

class TruncatingFormatterTest extends Unit
{
    public function testCapsFormattedStrings(): void
    {
        $formatter = new class implements FormatterInterface {
            public string $formatted = '';

            public function format($record): string
            {
                return $this->formatted;
            }

            public function formatBatch(array $records): string
            {
                return $this->formatted;
            }
        };
        $truncatingFormatter = new TruncatingFormatter($formatter);

        $formatter->formatted = "short\r\n";
        $this->assertSame($formatter->formatted, $truncatingFormatter->format(null));

        $formatter->formatted = str_repeat('é', 240 * 1024) . "\r\n";
        $formatted = $truncatingFormatter->format(null);

        $this->assertLessThanOrEqual(240 * 1024, strlen($formatted));
        $this->assertTrue(mb_check_encoding($formatted, 'UTF-8'));
        $this->assertStringEndsWith("... [truncated]\r\n", $formatted);
    }
}
