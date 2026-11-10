<?php

declare(strict_types=1);

/*
 * Handler for Craft Cloud's queue function. Its path is referenced by the Cloud infrastructure, so it must not move.
 *
 * Runs queued Laravel jobs in this process with bref/laravel-bridge, using the default (Craft Cloud SQS) connection.
 */

use Bref\Bref;
use Bref\LaravelBridge\Queue\QueueHandler;

require __DIR__ . '/../../../../../autoload.php';

return Bref::getContainer()->get(QueueHandler::class);
