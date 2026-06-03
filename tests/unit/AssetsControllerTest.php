<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\controllers\AssetsController;
use craft\elements\Asset;
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

    public function testImageUploadsUseUploadedImageDimensions(): void
    {
        $controller = new DimensionTestAssetsController('cloud-assets', Craft::$app);
        $controller->uploadedImageDimensions = [2139, 3020];

        $this->assertSame([2139, 3020], $controller->uploadedImageDimensionsForTest(
            new Asset(),
            'upload.jpeg',
        ));
        $this->assertSame(1, $controller->readCount);
    }

    public function testImageUploadsUseNullDimensionsWhenUploadedImageDimensionsCannotBeRead(): void
    {
        $controller = new DimensionTestAssetsController('cloud-assets', Craft::$app);

        $this->assertSame([null, null], $controller->uploadedImageDimensionsForTest(
            new Asset(),
            'upload.jpeg',
        ));
        $this->assertSame(1, $controller->readCount);
    }

    public function testNonImageUploadsUseNullDimensions(): void
    {
        $controller = new DimensionTestAssetsController('cloud-assets', Craft::$app);
        $controller->uploadedImageDimensions = [2139, 3020];

        $this->assertSame([null, null], $controller->uploadedImageDimensionsForTest(
            new Asset(),
            'document.pdf',
        ));
        $this->assertSame(0, $controller->readCount);
    }

    public function testReplacementUploadsUseServerDimensionsForUploadedFile(): void
    {
        $asset = new Asset();
        $asset->folderPath = 'uploads';

        $controller = new DimensionTestAssetsController('cloud-assets', Craft::$app);
        $controller->uploadedImageDimensions = [1080, 1440];

        $this->assertSame([1080, 1440], $controller->uploadedImageDimensionsForTest(
            $asset,
            'upload-replacement.jpeg',
        ));
        $this->assertSame(1, $controller->readCount);
    }

    private function invokeVolumeSubpath(Volume $volume): string
    {
        $controller = new AssetsController('cloud-assets', Craft::$app);
        $method = new ReflectionMethod($controller, 'volumeSubpath');
        $method->setAccessible(true);

        return $method->invoke($controller, $volume);
    }
}

class DimensionTestAssetsController extends AssetsController
{
    public ?array $uploadedImageDimensions = null;
    public int $readCount = 0;

    public function uploadedImageDimensionsForTest(Asset $asset, string $filename): array
    {
        $asset->setFilename($filename);

        return $this->uploadedImageDimensions($asset, $filename);
    }

    protected function readUploadedImageDimensions(Asset $asset): ?array
    {
        $this->readCount++;

        return $this->uploadedImageDimensions;
    }
}
