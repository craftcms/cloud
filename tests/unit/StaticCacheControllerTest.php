<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\cli\controllers\StaticCacheController;
use craft\cloud\Module;
use craft\cloud\StaticCache;
use craft\cloud\StaticCacheTag;

class StaticCacheControllerTest extends Unit
{
    private ?Module $previousModule = null;
    private TestStaticCache $staticCache;

    protected function _before(): void
    {
        parent::_before();

        $this->previousModule = Module::getInstance();
        $module = new Module('cloud');
        $module->getConfig()->environmentId = '123-environment-id';
        $this->staticCache = new TestStaticCache();
        $module->set('staticCache', $this->staticCache);
        Module::setInstance($module);
    }

    protected function _after(): void
    {
        Module::setInstance($this->previousModule);

        parent::_after();
    }

    public function testPurgeActions(): void
    {
        $controller = new StaticCacheController('static-cache', Craft::$app);

        $controller->actionPurgeAll();
        $controller->actionPurgeOrigin();
        $controller->actionPurgeCdn();
        $controller->actionPurgePrefixes('/first', '/second');
        $controller->actionPurgeTags('first', 'second');

        $this->assertSame([
            ['123-environment-id:uri', '123-environment-id:cdn', '123-environment-id:rasterize'],
            ['123-environment-id:uri'],
            ['123-environment-id:cdn', '123-environment-id:rasterize'],
            ['first', 'second'],
        ], $this->staticCache->purges);
        $this->assertSame([['/first', '/second']], $this->staticCache->purgedPrefixes);
    }

    public function testInvalidateActions(): void
    {
        $controller = new StaticCacheController('static-cache', Craft::$app);

        $controller->actionInvalidateAll();
        $controller->actionInvalidateOrigin();
        $controller->actionInvalidateCdn();
        $controller->actionInvalidatePrefixes('/first', '/second');
        $controller->actionInvalidateTags('first', 'second');

        $this->assertSame([
            ['123-environment-id:uri', '123-environment-id:cdn', '123-environment-id:rasterize'],
            ['123-environment-id:uri'],
            ['123-environment-id:cdn', '123-environment-id:rasterize'],
            ['first', 'second'],
        ], $this->staticCache->invalidations);
        $this->assertSame([['/first', '/second']], $this->staticCache->invalidatedPrefixes);
    }

    public function testPurgeFailurePropagates(): void
    {
        $this->staticCache->fail = true;
        $this->expectException(\RuntimeException::class);

        (new StaticCacheController('static-cache', Craft::$app))->actionPurgeAll();
    }

    public function testPurgeRequiresEnvironmentId(): void
    {
        Module::getInstance()->getConfig()->environmentId = null;
        $this->expectException(\yii\console\Exception::class);
        $this->expectExceptionMessage('Static cache purges require an environment ID.');

        (new StaticCacheController('static-cache', Craft::$app))->actionPurgeAll();
    }

    public function testPurgeRequiresNonEmptyEnvironmentId(): void
    {
        Module::getInstance()->getConfig()->environmentId = '';
        $this->expectException(\yii\console\Exception::class);
        $this->expectExceptionMessage('Static cache purges require an environment ID.');

        (new StaticCacheController('static-cache', Craft::$app))->actionPurgeAll();
    }
}

class TestStaticCache extends StaticCache
{
    public array $purges = [];
    public array $purgedPrefixes = [];
    public array $invalidations = [];
    public array $invalidatedPrefixes = [];
    public bool $fail = false;

    public function purgeTags(string|StaticCacheTag ...$tags): void
    {
        if ($this->fail) {
            throw new \RuntimeException('Purge failed.');
        }

        $this->purges[] = $tags;
    }

    public function purgeUrlPrefixes(string ...$urlPrefixes): void
    {
        $this->purgedPrefixes[] = $urlPrefixes;
    }

    public function invalidateTags(string|StaticCacheTag ...$tags): void
    {
        $this->invalidations[] = $tags;
    }

    public function invalidateUrlPrefixes(string ...$urlPrefixes): void
    {
        $this->invalidatedPrefixes[] = $urlPrefixes;
    }
}
