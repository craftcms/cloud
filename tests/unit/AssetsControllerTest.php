<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\controllers\AssetsController;
use craft\cloud\fs\Fs;
use craft\elements\Asset;
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

    public function testUploadedAssetMetadataUsesVolumeMetadata(): void
    {
        $controller = new SizeTestAssetsController('cloud-assets', Craft::$app);
        $fs = new HeaderTestFs();
        $fs->header = "\xFF\xD8"
            . "\xFF\xE1" . pack('n', 4) . 'xx'
            . "\xFF\xC0" . pack('n', 17) . "\x08" . pack('n', 3024) . pack('n', 4032) . str_repeat("\0", 10);
        $asset = new TestAsset(new TestVolume(123, $fs));
        $asset->setFilename('upload.jpeg');
        $asset->kind = Asset::KIND_IMAGE;

        $controller->setUploadedAssetMetadataForTest($asset, 'upload.jpeg');

        $this->assertSame(123, $asset->size);
        $this->assertSame(4032, $asset->getWidth());
        $this->assertSame(3024, $asset->getHeight());
        $this->assertSame(1, $fs->readCount);
    }

    public function testUploadedAssetMetadataFallsBackToRequestDimensions(): void
    {
        $controller = new SizeTestAssetsController('cloud-assets', Craft::$app);
        $controller->requestImageDimensions = [2496, 2496];
        $asset = new TestAsset(new TestVolume(123, new MissingDimensionsTestFs()));
        $asset->setFilename('upload.png');
        $asset->kind = Asset::KIND_IMAGE;

        $controller->setUploadedAssetMetadataForTest($asset, 'upload.png');

        $this->assertSame(123, $asset->size);
        $this->assertSame(2496, $asset->getWidth());
        $this->assertSame(2496, $asset->getHeight());
    }

    public function testUploadedAssetMetadataDeletesUploadedObjectOnValidationFailure(): void
    {
        $maxUploadSize = Craft::$app->getConfig()->getGeneral()->maxUploadFileSize;

        if (!$maxUploadSize) {
            $this->markTestSkipped('Max upload size is not configured.');
        }

        $controller = new SizeTestAssetsController('cloud-assets', Craft::$app);
        $volume = new TestVolume((int)$maxUploadSize + 1);
        $asset = new TestAsset($volume);
        $asset->folderPath = 'folder/';
        $asset->setFilename('original.jpeg');

        try {
            $controller->setUploadedAssetMetadataForTest($asset, 'upload.jpeg', 'original.jpeg');
        } catch (BadRequestHttpException) {
        }

        $this->assertSame(['folder/upload.jpeg'], $volume->deletedPaths);
    }

    public function testUploadedImageDimensionsRetryBoundedRanges(): void
    {
        $fs = new HeaderTestFs();
        $fs->headers = [
            "\xFF\xD8" . "\xFF\xE1" . pack('n', 4) . 'xx',
            "\xFF\xD8" . "\xFF\xE1" . pack('n', 4) . 'xx',
            "\xFF\xD8"
            . "\xFF\xE1" . pack('n', 4) . 'xx'
            . "\xFF\xC0" . pack('n', 17) . "\x08" . pack('n', 3024) . pack('n', 4032) . str_repeat("\0", 10),
        ];

        $this->assertSame([4032, 3024], $fs->getImageDimensions('upload.jpeg'));
        $this->assertSame(3, $fs->readCount);
    }

    private function invokeVolumeSubpath(Volume $volume): string
    {
        $controller = new AssetsController('cloud-assets', Craft::$app);
        $method = new ReflectionMethod($controller, 'volumeSubpath');
        $method->setAccessible(true);

        return $method->invoke($controller, $volume);
    }
}

class SizeTestAssetsController extends AssetsController
{
    public array $requestImageDimensions = [null, null];

    public function setUploadedAssetMetadataForTest(Asset $asset, string $filename, ?string $displayFilename = null): void
    {
        $this->setUploadedAssetMetadata($asset, $filename, $displayFilename);
    }

    protected function uploadedRequestImageDimensions(): array
    {
        return $this->requestImageDimensions;
    }
}

class TestAsset extends Asset
{
    private TestVolume $volume;

    public function __construct(TestVolume $volume)
    {
        $this->volume = $volume;

        parent::__construct();
    }

    public function getVolume(): Volume
    {
        return $this->volume;
    }
}

class HeaderTestFs extends Fs
{
    public string $header;
    public array $headers = [];
    public int $readCount = 0;

    public static function displayName(): string
    {
        return 'Header Test';
    }

    public function getFileStreamRange(string $uriPath, int $start, int $end)
    {
        $this->readCount++;

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $this->headers[$this->readCount - 1] ?? $this->header);
        rewind($stream);

        return $stream;
    }
}

class MissingDimensionsTestFs extends HeaderTestFs
{
    public function getImageDimensions(string $uriPath): ?array
    {
        return null;
    }
}

class TestVolume extends Volume
{
    public array $deletedPaths = [];
    private ?Fs $fs;
    private int $fileSize;

    public function __construct(int $fileSize, ?Fs $fs = null)
    {
        $this->fileSize = $fileSize;
        $this->fs = $fs;

        parent::__construct();
    }

    public function getFileSize(string $uri): int
    {
        return $this->fileSize;
    }

    public function getFs(): Fs
    {
        return $this->fs ?? new HeaderTestFs();
    }

    public function deleteFile(string $path): void
    {
        $this->deletedPaths[] = $path;
    }
}
