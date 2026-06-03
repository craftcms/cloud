<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\controllers\AssetsController;
use craft\cloud\fs\Fs;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\models\Volume;
use ReflectionMethod;
use yii\web\BadRequestHttpException;

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

    public function testUploadedAssetSizeUsesActualVolumeSize(): void
    {
        $controller = new DimensionTestAssetsController('cloud-assets', Craft::$app);
        $asset = new SizeTestAsset(123);
        $asset->setFilename('upload.jpeg');

        $this->assertSame(123, $controller->uploadedAssetSizeForTest($asset, 'upload.jpeg'));
    }

    public function testUploadedAssetSizeRejectsOversizedActualFile(): void
    {
        $controller = new DimensionTestAssetsController('cloud-assets', Craft::$app);
        $asset = new SizeTestAsset((int)AssetsHelper::getMaxUploadSize() + 1);
        $asset->setFilename('upload.jpeg');

        $this->expectException(BadRequestHttpException::class);

        $controller->uploadedAssetSizeForTest($asset, 'upload.jpeg');
    }

    public function testImageDimensionsCanBeReadFromBoundedJpegHeader(): void
    {
        $fs = new HeaderTestFs();
        $jpeg = "\xFF\xD8"
            . "\xFF\xE1" . pack('n', 4) . 'xx'
            . "\xFF\xC0" . pack('n', 17) . "\x08" . pack('n', 3020) . pack('n', 2139) . str_repeat("\0", 10);

        $fs->header = $jpeg;

        $this->assertSame([2139, 3020], $fs->getImageDimensions('upload.jpeg'));
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

    public function uploadedAssetSizeForTest(Asset $asset, string $filename): int
    {
        return $this->uploadedAssetSize($asset, $filename);
    }

    protected function readUploadedImageDimensions(Asset $asset): ?array
    {
        $this->readCount++;

        return $this->uploadedImageDimensions;
    }
}

class HeaderTestFs extends Fs
{
    public string $header;

    public static function displayName(): string
    {
        return 'Header Test';
    }

    public function getFileStreamRange(string $uriPath, int $start, int $end)
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $this->header);
        rewind($stream);

        return $stream;
    }
}

class SizeTestAsset extends Asset
{
    private int $fileSize;

    public function __construct(int $fileSize)
    {
        $this->fileSize = $fileSize;

        parent::__construct();
    }

    public function getVolume(): Volume
    {
        return new class($this->fileSize) extends Volume {
            private int $fileSize;

            public function __construct(int $fileSize)
            {
                $this->fileSize = $fileSize;

                parent::__construct();
            }

            public function getFileSize(string $uri): int
            {
                return $this->fileSize;
            }
        };
    }
}
