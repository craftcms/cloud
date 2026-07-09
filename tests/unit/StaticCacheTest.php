<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\HeaderEnum;
use craft\cloud\Module;
use craft\cloud\StaticCache;
use ReflectionMethod;

class StaticCacheTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    private ?string $requestMethod = null;
    private ?Module $previousModule = null;

    protected function _before(): void
    {
        parent::_before();

        $this->previousModule = Module::getInstance();
        $module = new Module('cloud');
        Module::setInstance($module);

        Craft::$app->getRequest()->setIsCpRequest(false);
        Craft::$app->getResponse()->clear();
        Craft::$app->getResponse()->setStatusCode(200);

        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function _after(): void
    {
        Craft::$app->getRequest()->setIsCpRequest(null);
        Craft::$app->getResponse()->clear();

        if ($this->requestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->requestMethod;
        }

        Module::setInstance($this->previousModule);

        parent::_after();
    }

    public function testCpResponsesAreNotCacheable(): void
    {
        $staticCache = new StaticCache();

        Craft::$app->getRequest()->setIsCpRequest(true);

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

    public function testStaticCacheDirectivesPreferCdnCacheControl(): void
    {
        $staticCache = new StaticCache();

        Craft::$app->getResponse()->getHeaders()->set(
            HeaderEnum::CACHE_CONTROL->value,
            'public,max-age=60',
        );
        Craft::$app->getResponse()->getHeaders()->set(
            HeaderEnum::CDN_CACHE_CONTROL->value,
            'no-store',
        );

        $this->assertSame(
            ['no-store'],
            $this->staticCacheDecision($staticCache)['directives']->all(),
        );

        $this->assertSame(
            'cdn-cache-control',
            $this->staticCacheDecision($staticCache)['source'],
        );
    }

    public function testStaticCacheDirectivesFallbackToCacheControl(): void
    {
        $staticCache = new StaticCache();

        Craft::$app->getResponse()->getHeaders()->set(
            HeaderEnum::CACHE_CONTROL->value,
            'public,max-age=60',
        );

        $decision = $this->staticCacheDecision($staticCache);

        $this->assertSame('cache-control', $decision['source']);
        $this->assertSame(['public,max-age=60'], $decision['directives']->all());
    }

    public function testStaticCacheDirectivesUseCloudDefaults(): void
    {
        $staticCache = new StaticCache();

        $decision = $this->staticCacheDecision($staticCache);

        $this->assertSame('cloud-default', $decision['source']);
        $this->assertSame('public', $decision['directives']->first());
        $this->assertContains('stale-while-revalidate=3600', $decision['directives']->all());
    }

    public function testStaticCacheDecisionReportsBlockers(): void
    {
        $staticCache = new StaticCache();

        Craft::$app->getResponse()->getHeaders()->set(
            HeaderEnum::CDN_CACHE_CONTROL->value,
            'private,max-age=0',
        );
        Craft::$app->getResponse()->getHeaders()->add(
            HeaderEnum::SET_COOKIE->value,
            'CraftSessionId=session-id; path=/',
        );

        $this->assertSame(
            ['private', 'max-age=0', 'set-cookie'],
            $this->staticCacheDecision($staticCache)['blockers'],
        );
    }

    private function isCacheable(StaticCache $staticCache): bool
    {
        $method = new ReflectionMethod($staticCache, 'isCacheable');
        $method->setAccessible(true);

        return $method->invoke($staticCache);
    }

    private function staticCacheDecision(StaticCache $staticCache): array
    {
        $method = new ReflectionMethod($staticCache, 'staticCacheDecision');
        $method->setAccessible(true);

        return $method->invoke($staticCache);
    }
}
