<?php

namespace craft\cloud\web;

use Craft;
use craft\helpers\App;
use craft\helpers\Template;
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

        Craft::info(new PsrMessage(
            'Session opened during request',
            $this->sessionLogContext(App::backtrace(8)),
        ), __METHOD__);
    }

    public function close()
    {
        $wasActive = $this->getIsActive();

        parent::close();

        if (!$wasActive) {
            return;
        }

        Craft::info(new PsrMessage(
            'Session saved during request',
            $this->sessionLogContext(App::backtrace(8)),
        ), __METHOD__);
    }

    private function sessionLogContext(string $stack, int $limit = 8): array
    {
        $context = [
            'stack' => $stack,
        ];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 2) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            $template = Template::resolveTemplatePathAndLine($file, $frame['line'] ?? null);

            if ($template === false) {
                continue;
            }

            [$path, $line] = $template;

            if ($path === null) {
                continue;
            }

            $resolved = [
                'path' => $path,
            ];

            if ($line !== null) {
                $resolved['line'] = $line;
            }

            $context['template'] = $resolved;
            break;
        }

        return $context;
    }
}
