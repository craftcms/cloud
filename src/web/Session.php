<?php

namespace craft\cloud\web;

use Craft;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Support\Collection;
use samdark\log\PsrMessage;
use yii\web\DbSession;

class Session extends DbSession
{
    public function open()
    {
        $wasActive = $this->getIsActive();

        parent::open();

        if ($wasActive || !$this->getIsActive()) {
            return;
        }

        Craft::info(new PsrMessage('Session opened during request', [
            'sessionStatus' => session_status(),
            'sessionCacheLimiter' => session_cache_limiter() ?: null,
            'nativeHeaders' => $this->nativeCacheHeaders(),
            'stack' => $this->filteredStackTrace(),
        ]), __METHOD__);
    }

    public function close()
    {
        $wasActive = $this->getIsActive();

        parent::close();

        if (!$wasActive) {
            return;
        }

        Craft::info(new PsrMessage('Session saved during request', [
            'sessionStatus' => session_status(),
            'sessionCacheLimiter' => session_cache_limiter() ?: null,
            'nativeHeaders' => $this->nativeCacheHeaders(),
            'stack' => $this->filteredStackTrace(),
        ]), __METHOD__);
    }

    private function nativeCacheHeaders(): array
    {
        $trackedHeaders = [
            'Cache-Control',
            'CDN-Cache-Control',
            'Pragma',
            'Expires',
            'Set-Cookie',
            'Surrogate-Control',
        ];

        return Collection::make(GuzzleUtils::headersFromLines(headers_list()))
            ->filter(fn(array $values, string $name) => Collection::make($trackedHeaders)
                ->contains(fn(string $trackedHeader) => strcasecmp($trackedHeader, $name) === 0))
            ->all();
    }

    private function filteredStackTrace(int $limit = 8): array
    {
        return Collection::make(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
            ->reject(fn(array $frame) => ($frame['class'] ?? null) === self::class)
            ->map(function(array $frame) {
                $callable = Collection::make([
                    $frame['class'] ?? null,
                    $frame['type'] ?? null,
                    $frame['function'],
                ])->filter()->implode('');

                $location = Collection::make([
                    $frame['file'] ?? null,
                    $frame['line'] ?? null,
                ])->filter()->implode(':');

                return trim("$callable $location");
            })
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }
}
