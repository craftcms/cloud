<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\events\PurgeEvent;
use craft\cloud\fs\AssetsFs;
use craft\cloud\HeaderEnum;
use craft\cloud\Module;
use craft\cloud\signing\RequestSigner;
use craft\cloud\StaticCache;
use craft\cloud\StaticCacheTag;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\helpers\StringHelper;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Psr\Http\Message\RequestInterface;
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
    private ?RequestInterface $gatewayRequest = null;
    private ?\Throwable $gatewayException = null;

    protected function _before(): void
    {
        parent::_before();

        $this->previousModule = Module::getInstance();
        $module = new Module('cloud');
        Module::setInstance($module);

        Craft::$app->getRequest()->setIsCpRequest(false);
        Craft::$app->getResponse()->clear();
        Craft::$app->getResponse()->setStatusCode(200);

        $this->environmentId = $module->getConfig()->environmentId;
        $module->getConfig()->environmentId = '123-environment-id';
        $module->getConfig()->signingKey = 'test-signing-key';
        $module->set('requestSigner', new class(function(RequestInterface $request) {
            $this->gatewayRequest = $request;

            if ($this->gatewayException) {
                throw $this->gatewayException;
            }
        }) extends RequestSigner {
            public function __construct(private readonly \Closure $capture)
            {
                parent::__construct('test-signing-key');
            }

            public function createHandlerStack(?HandlerStack $handlerStack = null): HandlerStack
            {
                return new HandlerStack(function(RequestInterface $request) {
                    ($this->capture)($request);

                    return Create::promiseFor(new Response(204));
                });
            }
        });

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

    public function testCacheTagOverflowTruncatesTagsThatExceedTheMaximumLength(): void
    {
        $staticCache = new StaticCache();
        $this->setTags($staticCache, Collection::make([
            StaticCacheTag::create(str_repeat('x', 1025)),
            StaticCacheTag::create('second'),
        ]));

        $this->addCacheHeadersToWebResponse($staticCache);

        $this->assertSame(
            '123-environment-id:overflow',
            Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_TAG->value),
        );
    }

    public function testCacheTagAtMaximumLengthIsAddedToTheHeader(): void
    {
        $staticCache = new StaticCache();
        $tag = str_repeat('x', 1024);
        $this->setTags($staticCache, Collection::make([
            StaticCacheTag::create($tag),
        ]));

        $this->addCacheHeadersToWebResponse($staticCache);

        $this->assertSame(
            $tag,
            Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_TAG->value),
        );
    }

    public function testCacheTagOverflowTruncatesTagsThatExceedTheMaximumCount(): void
    {
        $staticCache = new StaticCache();
        $this->setTags($staticCache, Collection::range(1, 1001)
            ->map(fn(int $index) => StaticCacheTag::create("tag-$index")));

        $this->addCacheHeadersToWebResponse($staticCache);

        $tags = explode(',', Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_TAG->value));

        $this->assertSame('123-environment-id:overflow', $tags[0]);
        $this->assertCount(1000, $tags);
        $this->assertContains('tag-999', $tags);
        $this->assertNotContains('tag-1000', $tags);
    }

    public function testPurgeTagsKeepExistingHeaderTags(): void
    {
        $staticCache = new StaticCache();
        Craft::$app->getResponse()->getHeaders()->set(HeaderEnum::CACHE_PURGE_TAG->value, 'first,second');

        $staticCache->purgeTags();

        $this->assertSame(
            'first,second',
            Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value),
        );
    }

    public function testPurgeAllUsesOriginAndCdnTags(): void
    {
        $staticCache = new StaticCache();

        $staticCache->purgeAll();

        $this->assertSame(
            ['123-environment-id:uri', '123-environment-id:cdn'],
            $this->collectionProperty($staticCache, 'tagsToPurge')
                ->map(fn(StaticCacheTag $tag) => $tag->getValue())
                ->all(),
        );
    }

    public function testAssetCdnPurgeUsesEnvironmentFirstTag(): void
    {
        $staticCache = new StaticCache();
        Module::getInstance()->set('staticCache', $staticCache);
        $fs = new AssetsFs();
        $method = new ReflectionMethod($fs, 'invalidateCdnPath');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($fs, 'image.jpg'));
        $this->assertFalse(
            Craft::$app->getResponse()->getHeaders()->has(HeaderEnum::CACHE_PURGE_TAG->value),
        );

        $this->sendPendingPurgeTags($staticCache);

        $this->assertSame(
            '123-environment-id:cdn:123-environment-id/assets/image.jpg',
            Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value),
        );
    }

    public function testOverlongAssetCdnPurgeUsesOverflowTag(): void
    {
        $staticCache = new StaticCache();
        Module::getInstance()->set('staticCache', $staticCache);
        $fs = new AssetsFs();
        $method = new ReflectionMethod($fs, 'invalidateCdnPath');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($fs, str_repeat('x', 1024)));
        $this->sendPendingPurgeTags($staticCache);

        $this->assertSame(
            '123-environment-id:overflow',
            Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value),
        );
    }

    public function testBeforePurgeEventCanCancelPurge(): void
    {
        $staticCache = new StaticCache();
        $staticCache->on(StaticCache::EVENT_BEFORE_PURGE, function(PurgeEvent $event) {
            $this->assertContainsOnlyInstancesOf(StaticCacheTag::class, $event->tags);
            $event->isValid = false;
        });

        $staticCache->purgeTags('first');

        $this->assertFalse(
            Craft::$app->getResponse()->getHeaders()->has(HeaderEnum::CACHE_PURGE_TAG->value),
        );
    }

    public function testDraftCacheInvalidationDoesNotPurge(): void
    {
        $staticCache = new StaticCache();
        $method = new ReflectionMethod($staticCache, 'handleInvalidateElementCaches');
        $method->setAccessible(true);
        $eventConfig = property_exists(InvalidateElementCachesEvent::class, 'element')
            ? [
                'element' => new Entry(['draftId' => 1]),
                'tags' => ['element::craft\\elements\\Entry::1'],
            ]
            : [
                'tags' => ['element::craft\\elements\\Entry::drafts'],
            ];
        $method->invoke($staticCache, new InvalidateElementCachesEvent($eventConfig));

        $this->assertTrue($this->collectionProperty($staticCache, 'tagsToPurge')->isEmpty());
    }

    public function testFailedFetchRequestFallsBackToPurgeHeader(): void
    {
        $staticCache = new StaticCache();
        $element = new FetchableElement(['uri' => 'news']);
        $element->fetchUrl = 'https://example.com/news';
        $this->gatewayException = new \RuntimeException();

        $this->saveElement($staticCache, $element);

        try {
            $this->sendPendingPurgeTags($staticCache);
        } catch (\RuntimeException) {
        }

        $this->assertSame(
            '123-environment-id:uri:/news',
            Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value),
        );
    }

    public function testBeforePurgeEventCanCancelExistingHeaderTags(): void
    {
        $staticCache = new StaticCache();
        Craft::$app->getResponse()->getHeaders()->set(HeaderEnum::CACHE_PURGE_TAG->value, 'first');
        $staticCache->on(StaticCache::EVENT_BEFORE_PURGE, function(PurgeEvent $event) {
            $event->isValid = false;
        });

        $staticCache->purgeTags();

        $this->assertFalse(
            Craft::$app->getResponse()->getHeaders()->has(HeaderEnum::CACHE_PURGE_TAG->value),
        );
    }

    public function testBeforePurgeEventCanReplaceTags(): void
    {
        $staticCache = new StaticCache();
        $staticCache->on(StaticCache::EVENT_BEFORE_PURGE, function(PurgeEvent $event) {
            $event->tags = [StaticCacheTag::create('second')];
        });

        $staticCache->purgeTags('first');

        $this->assertSame(
            'second',
            Craft::$app->getResponse()->getHeaders()->get(HeaderEnum::CACHE_PURGE_TAG->value),
        );
    }

    public function testBeforePurgeEventReceivesElements(): void
    {
        $staticCache = new StaticCache();
        $news = new Entry(['uri' => 'news']);
        $about = new Entry(['uri' => 'about']);
        $purgeEvent = null;
        $staticCache->on(StaticCache::EVENT_BEFORE_PURGE, function(PurgeEvent $event) use (&$purgeEvent) {
            $purgeEvent = $event;
        });

        $this->saveElement($staticCache, $news);
        $this->saveElement($staticCache, $about);
        $this->assertNull($purgeEvent);
        $this->sendPendingPurgeTags($staticCache);

        $this->assertSame([$news, $about], $purgeEvent->elements);
        $this->assertSame(
            [
                '123-environment-id:uri:/news',
                '123-environment-id:uri:/about',
            ],
            array_map(fn(StaticCacheTag $tag) => $tag->getValue(), $purgeEvent->tags),
        );
    }

    public function testHomepagePurgeUsesUriTag(): void
    {
        $staticCache = new StaticCache();
        $element = new Entry(['uri' => Entry::HOMEPAGE_URI]);

        $this->saveElement($staticCache, $element);

        $this->assertSame(
            ['123-environment-id:uri:/'],
            $this->collectionProperty($staticCache, 'tagsToPurge')
                ->map(fn(StaticCacheTag $tag) => $tag->getValue())
                ->all(),
        );
    }

    public function testOverlongElementUriPurgeUsesOverflowTag(): void
    {
        $staticCache = new StaticCache();
        $element = new FetchableElement(['uri' => str_repeat('x', 1024)]);
        $element->fetchUrl = 'https://example.com/overlong';

        $this->saveElement($staticCache, $element);
        $this->sendPendingPurgeTags($staticCache);

        $payload = json_decode(
            (string) $this->gatewayRequest?->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(['123-environment-id:overflow'], $payload['tags']);
    }

    public function testCancelledElementPurgeDoesNotSendFetch(): void
    {
        $staticCache = new StaticCache();
        $element = new FetchableElement(['uri' => 'news']);
        $element->fetchUrl = 'https://example.com/news';
        $staticCache->on(StaticCache::EVENT_BEFORE_PURGE, function(PurgeEvent $event) {
            $event->isValid = false;
        });

        $this->saveElement($staticCache, $element);
        $this->sendPendingPurgeTags($staticCache);

        $this->assertTrue($this->collectionProperty($staticCache, 'tagsToPurge')->isEmpty());
        $this->assertTrue($this->collectionProperty($staticCache, 'fetchUrls')->isEmpty());
        $this->assertNull($this->gatewayRequest);
    }

    public function testDeletedElementPurgeDoesNotCollectFetch(): void
    {
        $staticCache = new StaticCache();
        $element = new FetchableElement(['uri' => 'news']);
        $element->fetchUrl = 'https://example.com/news';

        $this->deleteElement($staticCache, $element);

        $this->assertTrue($this->collectionProperty($staticCache, 'tagsToPurge')->isNotEmpty());
        $this->assertTrue($this->collectionProperty($staticCache, 'fetchUrls')->isEmpty());
    }

    public function testSavedElementPurgeRequestIncludesFetchUrls(): void
    {
        $staticCache = new StaticCache();
        $englishElement = new FetchableElement(['uri' => 'news']);
        $englishElement->fetchUrl = 'https://example.com/news';
        $frenchElement = clone $englishElement;
        $frenchElement->fetchUrl = 'https://example.com/fr/nouvelles';
        $urlLessElement = clone $englishElement;
        $urlLessElement->fetchUrl = null;

        $this->saveElement($staticCache, $englishElement);
        $this->saveElement($staticCache, $englishElement);
        $this->saveElement($staticCache, $frenchElement);
        $this->saveElement($staticCache, $urlLessElement);
        $this->sendPendingPurgeTags($staticCache);

        $payload = json_decode(
            (string) $this->gatewayRequest?->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(['123-environment-id:uri:/news'], $payload['tags']);
        $this->assertSame([
            'https://example.com/news',
            'https://example.com/fr/nouvelles',
        ], $payload['fetchUrls']);
        $this->assertFalse(
            Craft::$app->getResponse()->getHeaders()->has(HeaderEnum::CACHE_PURGE_TAG->value),
        );
    }

    public function testBeforePurgeEventFiresOnceForCollectedBatch(): void
    {
        $staticCache = new StaticCache();
        $eventCount = 0;
        $eventTags = [];
        $staticCache->on(StaticCache::EVENT_BEFORE_PURGE, function(PurgeEvent $event) use (&$eventCount, &$eventTags) {
            $eventCount++;
            $eventTags = array_map(fn(StaticCacheTag $tag) => $tag->getValue(), $event->tags);
        });

        $staticCache->purgeAll();

        $this->assertSame(0, $eventCount);
        $this->sendPendingPurgeTags($staticCache);
        $this->assertSame(1, $eventCount);
        $this->assertSame([
            '123-environment-id:uri',
            '123-environment-id:cdn',
        ], $eventTags);
    }

    public function testExistingCacheTagHeaderIsSplit(): void
    {
        $staticCache = new StaticCache();
        Craft::$app->getResponse()->getHeaders()->set(HeaderEnum::CACHE_TAG->value, '0, , second');

        $this->addCacheHeadersToWebResponse($staticCache);

        $this->assertSame(
            '0,second',
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

    private function saveElement(StaticCache $staticCache, Entry $element): void
    {
        $method = new ReflectionMethod($staticCache, 'handleSaveElement');
        $method->setAccessible(true);
        $method->invoke($staticCache, new ElementEvent(['element' => $element]));
    }

    private function deleteElement(StaticCache $staticCache, Entry $element): void
    {
        $method = new ReflectionMethod($staticCache, 'handleDeleteElement');
        $method->setAccessible(true);
        $method->invoke($staticCache, new ElementEvent(['element' => $element]));
    }

    private function sendPendingPurgeTags(StaticCache $staticCache): void
    {
        $method = new ReflectionMethod($staticCache, 'sendPurgeTagsRequest');
        $method->setAccessible(true);
        $method->invoke($staticCache);
    }

    private function collectionProperty(StaticCache $staticCache, string $name): Collection
    {
        $property = new ReflectionProperty($staticCache, $name);
        $property->setAccessible(true);

        return $property->getValue($staticCache);
    }

    private function setTags(StaticCache $staticCache, Collection $tags): void
    {
        $property = new ReflectionProperty($staticCache, 'tags');
        $property->setAccessible(true);
        $property->setValue($staticCache, $tags);
    }
}

class FetchableElement extends Entry
{
    public ?string $fetchUrl = null;

    public function getUrl(): ?string
    {
        return $this->fetchUrl;
    }
}
