<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\HeaderEnum;
use craft\cloud\Module as CloudModule;
use craft\cloud\StaticCache;
use ReflectionMethod;
use ReflectionProperty;

class StaticCacheTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    protected function _before(): void
    {
        parent::_before();

        if (CloudModule::getInstance() === null) {
            $module = new CloudModule('cloud');
            $module->bootstrap(Craft::$app);
        }

        Craft::$app->getRequest()->setIsCpRequest(false);
        Craft::$app->getResponse()->clear();
        Craft::$app->getResponse()->setStatusCode(200);
    }

    protected function _after(): void
    {
        Craft::$app->getRequest()->setIsCpRequest(null);
        Craft::$app->getResponse()->clear();

        parent::_after();
    }

    public function testNonTemplateResponseWithoutTagsDoesNotAddCacheHeaders(): void
    {
        $this->addCacheHeadersToWebResponse(new StaticCache());

        $headers = Craft::$app->getResponse()->getHeaders();

        $this->assertFalse($headers->has(HeaderEnum::CDN_CACHE_CONTROL->value));
        $this->assertFalse($headers->has(HeaderEnum::CACHE_TAG->value));
    }

    public function testNonTemplateResponseWithTagsAddsCacheHeaders(): void
    {
        $headers = Craft::$app->getResponse()->getHeaders();
        $headers->add(HeaderEnum::CACHE_TAG->value, 'headless-tag');

        $this->addCacheHeadersToWebResponse(new StaticCache());

        $this->assertMatchesRegularExpression('/^public,max-age=\d+,stale-while-revalidate=3600$/', $headers->get(HeaderEnum::CDN_CACHE_CONTROL->value));
        $this->assertSame(['headless-tag'], $headers->get(HeaderEnum::CACHE_TAG->value, first: false));
    }

    public function testTemplateResponseWithoutTagsStillAddsCacheHeaders(): void
    {
        $staticCache = new StaticCache();
        $this->setRenderedPageTemplate($staticCache);

        $this->addCacheHeadersToWebResponse($staticCache);

        $headers = Craft::$app->getResponse()->getHeaders();

        $this->assertMatchesRegularExpression('/^public,max-age=\d+,stale-while-revalidate=3600$/', $headers->get(HeaderEnum::CDN_CACHE_CONTROL->value));
        $this->assertFalse($headers->has(HeaderEnum::CACHE_TAG->value));
    }

    public function testCpResponsesAreNotCacheable(): void
    {
        $staticCache = new StaticCache();

        Craft::$app->getRequest()->setIsCpRequest(true);

        $this->assertFalse($this->isCacheable($staticCache));
    }

    public function testSiteResponsesAreCacheable(): void
    {
        $staticCache = new StaticCache();

        Craft::$app->getRequest()->setIsCpRequest(false);

        $this->assertTrue($this->isCacheable($staticCache));
    }

    public function testErrorResponsesAreNotCacheable(): void
    {
        $staticCache = new StaticCache();

        Craft::$app->getResponse()->setStatusCode(500);

        $this->assertFalse($this->isCacheable($staticCache));
    }

    private function addCacheHeadersToWebResponse(StaticCache $staticCache): void
    {
        $method = new ReflectionMethod($staticCache, 'addCacheHeadersToWebResponse');
        $method->setAccessible(true);
        $method->invoke($staticCache);
    }

    private function isCacheable(StaticCache $staticCache): bool
    {
        $method = new ReflectionMethod($staticCache, 'isCacheable');
        $method->setAccessible(true);

        return $method->invoke($staticCache);
    }

    private function setRenderedPageTemplate(StaticCache $staticCache): void
    {
        $property = new ReflectionProperty($staticCache, 'renderedPageTemplate');
        $property->setAccessible(true);
        $property->setValue($staticCache, true);
    }
}
