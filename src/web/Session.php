<?php

namespace craft\cloud\web;

use Craft;
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
            'stack' => $this->filteredStackTrace(),
        ]), __METHOD__);
    }

    private function filteredStackTrace(int $limit = 8): array
    {
        return Collection::make(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 2))
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
