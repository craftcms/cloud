<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\controllers\AssetsController;
use craft\models\Volume;
use ReflectionMethod;

class AssetsControllerTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    public function testVolumeSubpathReturnsEmptyStringOnCraft4(): void
    {
        $volume = new Volume();

        if (method_exists($volume, 'getSubpath')) {
            $this->markTestSkipped('Craft 5 volume subpath behavior is covered by a separate test.');
        }

        $this->assertSame('', $this->invokeVolumeSubpath($volume));
    }

    public function testVolumeSubpathReturnsVolumeSubpathOnCraft5(): void
    {
        $volume = new Volume();

        if (!method_exists($volume, 'getSubpath')) {
            $this->markTestSkipped('Craft 4 volumes do not implement getSubpath().');
        }

        $volume->setSubpath('volume-prefix');

        $this->assertSame('volume-prefix/', $this->invokeVolumeSubpath($volume));
    }

    private function invokeVolumeSubpath(Volume $volume): string
    {
        $controller = new AssetsController('cloud-assets', Craft::$app);
        $method = new ReflectionMethod($controller, 'volumeSubpath');
        $method->setAccessible(true);

        return $method->invoke($controller, $volume);
    }
}
