<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\StaticCache;
use craft\helpers\Session as SessionHelper;
use ReflectionMethod;

class StaticCacheTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    private ?string $requestMethod = null;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(false);
        Craft::$app->getResponse()->clear();
        Craft::$app->getResponse()->setStatusCode(200);
        Craft::$app->getSession()->setHasSessionId(false);
        SessionHelper::reset();

        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function _after(): void
    {
        Craft::$app->getRequest()->setIsCpRequest(null);
        Craft::$app->getResponse()->clear();
        Craft::$app->getSession()->setHasSessionId(false);
        SessionHelper::reset();

        if ($this->requestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->requestMethod;
        }

        parent::_after();
    }

    public function testCpResponsesAreNotCacheable(): void
    {
        $staticCache = new StaticCache();

        Craft::$app->getRequest()->setIsCpRequest(true);

        $this->assertFalse($this->isCacheable($staticCache));
    }

    public function testSessionResponsesAreNotCacheable(): void
    {
        $staticCache = new StaticCache();

        Craft::$app->getSession()->setHasSessionId(true);

        $this->assertFalse($this->isCacheable($staticCache));
    }

    public function testReadResponsesAreCacheable(): void
    {
        $staticCache = new StaticCache();

        foreach (['GET', 'HEAD'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;

            $this->assertTrue($this->isCacheable($staticCache));
        }
    }

    public function testPostResponsesAreNotCacheable(): void
    {
        $staticCache = new StaticCache();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->assertFalse($this->isCacheable($staticCache));
    }

    private function isCacheable(StaticCache $staticCache): bool
    {
        $method = new ReflectionMethod($staticCache, 'isCacheable');
        $method->setAccessible(true);

        return $method->invoke($staticCache);
    }
}
