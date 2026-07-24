<?php

namespace craft\cloud;

use Monolog\Formatter\FormatterInterface;

class TruncatingFormatter implements FormatterInterface
{
    /**
     * Caps records at 240 KiB, leaving 10 KiB below Bref's 256000-byte FPM limit.
     *
     * @see https://github.com/brefphp/aws-lambda-layers/blob/main/src/php-fpm.conf Bref's FPM configuration
     * @see https://www.php.net/manual/en/install.fpm.configuration.php#log-limit PHP's log_limit
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-logs-api.html#runtimes-logs-api-buffering Lambda Logs API buffering
     * @see https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_InputLogEvent.html CloudWatch InputLogEvent limits
     */
    private const MAX_BYTES = 240 * 1024;
    private const SUFFIX = '... [truncated]';

    public function __construct(private readonly FormatterInterface $formatter)
    {
    }

    public function format($record)
    {
        return $this->truncate($this->formatter->format($record));
    }

    public function formatBatch(array $records)
    {
        return $this->truncate($this->formatter->formatBatch($records));
    }

    private function truncate(mixed $formatted): mixed
    {
        if (is_array($formatted)) {
            return array_map($this->truncate(...), $formatted);
        }

        if (!is_string($formatted) || strlen($formatted) <= self::MAX_BYTES) {
            return $formatted;
        }

        $lineEnding = preg_match('/\R\z/', $formatted, $matches) ? $matches[0] : '';
        $bytes = self::MAX_BYTES - strlen(self::SUFFIX . $lineEnding);

        return mb_strcut($formatted, 0, $bytes, 'UTF-8') . self::SUFFIX . $lineEnding;
    }
}
