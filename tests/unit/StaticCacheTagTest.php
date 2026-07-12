<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\Module;
use craft\cloud\StaticCacheTag;

class StaticCacheTagTest extends Unit
{
    public function testPreservesValidTags(): void
    {
        $this->assertSame('tag*value', StaticCacheTag::create('tag*value')->getValue());
        $this->assertSame('tag:value', StaticCacheTag::create('tag:value')->getValue());
    }

    public function testDropsInvalidTags(): void
    {
        foreach (["tag value", 'tag,value', "tag\tvalue", 'tagévalue'] as $value) {
            $this->assertSame('', StaticCacheTag::create($value)->getValue());
        }
    }

    public function testMinifiesOriginalValueBeforeValidation(): void
    {
        $this->assertSame(
            Module::getInstance()->getConfig()->getShortEnvironmentId() . sprintf('%x', crc32('tag*value')),
            StaticCacheTag::create('tag*value')->minify(true)->getValue(),
        );
    }
}
