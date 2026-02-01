<?php

namespace craft\cloud\ops\bref\curl;

final class CurlResponse
{
    public function __construct(
        public readonly string|bool $body,
        public readonly int $statusCode,
        public readonly string $curlError,
    ) {
    }

    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
