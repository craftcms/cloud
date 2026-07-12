<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\HeaderEnum;
use craft\cloud\Module;
use craft\cloud\StaticCache;
use craft\cloud\StaticCacheTag;
use craft\helpers\StringHelper;
use Illuminate\Support\Collection;
use ReflectionMethod;
use ReflectionProperty;

class StaticCacheTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    private ?string $requestMethod = null;
    private ?string $environmentId = null;
    private ?Module $previousModule = null;

    protected function _before(): void
    {
        parent::_before();

        $this->previousModule = Module::getInstance();
        Module::setInstance(new Module('cloud'));

        Craft::$app->getRequest()->setIsCpRequest(false);
        Craft::$app->getResponse()->clear();
        Craft::$app->getResponse()->setStatusCode(200);

        $this->environmentId = Module::getInstance()->getConfig()->environmentId;
        Module::getInstance()->getConfig()->environmentId = '123-environment-id';

        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function _after(): void
    {
        Craft::$app->getRequest()->setIsCpRequest(null);
        Craft::$app->getResponse()->clear();
        Module::getInstance()->getConfig()->environmentId = $this->environmentId;
        Module::setInstance($this->previousModule);

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
            $this->staticCacheDirectives($staticCache)->all(),
        );
    }

    public function testCacheTagOverflowUsesFallbackTag(): void
    {
        $staticCache = new StaticCache();
        $staticCache->init();
        $this->setTags($staticCache, Collection::range(1, 1000)
            ->map(fn(int $index) => StaticCacheTag::create("tag-$index-" . str_repeat('x', 24))));

        $this->addCacheHeadersToWebResponse($staticCache);

        $cacheTagHeader = Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_TAG->value);
        $tags = explode(',', $cacheTagHeader);

        $this->assertSame('123-environment-id:overflow', $tags[0]);
        $this->assertLessThanOrEqual(16 * 1024, StringHelper::byteLength($cacheTagHeader));
        $this->assertContains('tag-1-' . str_repeat('x', 24), $tags);
        $this->assertNotContains('tag-1000-' . str_repeat('x', 24), $tags);
    }

    public function testPurgeTagsIncludeOverflowFallbackTag(): void
    {
        $staticCache = new StaticCache();
        Craft::$app->getResponse()->getHeaders()->set(HeaderEnum::CACHE_PURGE_TAG->value, 'first,second');

        $staticCache->purgeTags();

        $this->assertSame(
            '123-environment-id:overflow,first,second',
            Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value),
        );
    }

    public function testExistingCacheTagHeaderIsSplit(): void
    {
        $staticCache = new StaticCache();
        $staticCache->init();
        Craft::$app->getResponse()->getHeaders()->set(HeaderEnum::CACHE_TAG->value, 'first,second');

        $this->addCacheHeadersToWebResponse($staticCache);

        $this->assertSame(
            'first,second',
            Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_TAG->value),
        );
    }

    public function testPurgeTagHeaderUsesOverflowFallbackWhenItIsTooLong(): void
    {
        $staticCache = new StaticCache();
        $tags = Collection::range(1, 1000)
            ->map(fn(int $index) => "tag-$index-" . str_repeat('x', 24))
            ->all();

        $staticCache->purgeTags(...$tags);

        $cachePurgeTagHeader = Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value);

        $this->assertStringStartsWith('123-environment-id:overflow,', $cachePurgeTagHeader);
        $this->assertLessThanOrEqual(16 * 1024, StringHelper::byteLength($cachePurgeTagHeader));
    }

    private function isCacheable(StaticCache $staticCache): bool
    {
        $method = new ReflectionMethod($staticCache, 'isCacheable');
        $method->setAccessible(true);

        return $method->invoke($staticCache);
    }

    private function staticCacheDirectives(StaticCache $staticCache): Collection
    {
        $method = new ReflectionMethod($staticCache, 'staticCacheDirectives');
        $method->setAccessible(true);

        return $method->invoke($staticCache);
    }

    private function addCacheHeadersToWebResponse(StaticCache $staticCache): void
    {
        $method = new ReflectionMethod($staticCache, 'addCacheHeadersToWebResponse');
        $method->setAccessible(true);
        $method->invoke($staticCache);
    }

    private function setTags(StaticCache $staticCache, Collection $tags): void
    {
        $property = new ReflectionProperty($staticCache, 'tags');
        $property->setAccessible(true);
        $property->setValue($staticCache, $tags);
    }
}
