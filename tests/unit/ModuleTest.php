<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\Module;
use samdark\log\PsrMessage;
use yii\log\Logger;

class ModuleTest extends Unit
{
    public function testLogAddsRequestContext(): void
    {
        $originalLogger = Craft::getLogger();
        $logger = new Logger(['flushInterval' => 0]);
        $route = Craft::$app->requestedRoute;
        $params = Craft::$app->requestedParams;
        $request = Craft::$app->getRequest();
        $hostInfo = $request->getHostInfo();
        $isConsoleRequest = $request->getIsConsoleRequest();

        try {
            Craft::setLogger($logger);
            Craft::$app->requestedRoute = 'templates/render';
            Craft::$app->requestedParams = ['template' => 'news/_entry'];
            $request->setHostInfo('https://example.com');
            $request->setUrl('/news/example?token=x&filter=recent');
            $request->setIsConsoleRequest(false);

            Module::info('Test', ['existing' => true]);
            $message = $logger->messages[array_key_last($logger->messages)][0];
        } finally {
            Craft::setLogger($originalLogger);
            Craft::$app->requestedRoute = $route;
            Craft::$app->requestedParams = $params;
            $request->setHostInfo($hostInfo);
            $request->setUrl(null);
            $request->setIsConsoleRequest($isConsoleRequest);
        }

        $this->assertInstanceOf(PsrMessage::class, $message);
        $this->assertSame([
            'existing' => true,
            'requestedRoute' => 'templates/render',
            'requestedParams' => ['template' => 'news/_entry'],
            'url' => 'https://example.com/news/example?token=x&filter=recent',
        ], $message->getContext());
    }
}
