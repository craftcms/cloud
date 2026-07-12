<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\Module;
use craft\cloud\StaticCacheTag;

class StaticCacheTagTest extends Unit
{
    private ?Module $previousModule = null;

    protected function _before(): void
    {
        parent::_before();

        $this->previousModule = Module::getInstance();
        Module::setInstance(new Module('cloud'));
        Module::getInstance()->getConfig()->environmentId = '123-environment-id';
    }

    protected function _after(): void
    {
        Module::setInstance($this->previousModule);

        parent::_after();
    }

    public function testPreservesValidTags(): void
    {
        $this->assertSame('tag*value', StaticCacheTag::create('tag*value')->getValue());
        $this->assertSame('tag:value', StaticCacheTag::create('tag:value')->getValue());
        $this->assertSame('0', StaticCacheTag::create('0')->getValue());
    }

    public function testNormalizesInvalidTags(): void
    {
        $this->assertSame('tag%20value', StaticCacheTag::create('tag value')->getValue());
        $this->assertSame('tag%2Cvalue', StaticCacheTag::create('tag,value')->getValue());
        $this->assertSame('tag%09value', StaticCacheTag::create("tag\tvalue")->getValue());
        $this->assertSame('tag%C3%A9value', StaticCacheTag::create('tagévalue')->getValue());
    }

    public function testMinifiesOriginalValueBeforeValidation(): void
    {
        $this->assertSame(
            Module::getInstance()->getConfig()->getShortEnvironmentId() . sprintf('%x', crc32('tag*value')),
            StaticCacheTag::create('tag*value')->minify(true)->getValue(),
        );
    }
}
