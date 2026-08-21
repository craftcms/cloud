<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\cli\controllers\assets\RepairController;
use craft\cloud\cli\controllers\assets\ReplaceMetadataController;
use craft\cloud\controllers\AssetsController;
use craft\cloud\fs\Fs;
use craft\console\Controller as ConsoleController;
use craft\elements\Asset;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\services\Assets as AssetsService;
use ReflectionMethod;
use yii\base\Module as BaseModule;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

class AssetsControllerTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    public function testGetUploadUrlRequiresSaveAssetsPermission(): void
    {
        $assets = Craft::$app->getAssets();
        $request = Craft::$app->getRequest();
        $bodyParams = $request->getBodyParams();
        $folder = new VolumeFolder(['id' => 1]);
        $assetsMock = $this->createMock(AssetsService::class);
        $assetsMock->method('findFolder')->willReturn($folder);
        Craft::$app->set('assets', $assetsMock);
        $request->setBodyParams(['filename' => 'test.jpg', 'folderId' => 1]);

        $controller = $this->getMockBuilder(AssetsController::class)
            ->setConstructorArgs(['cloud-assets', Craft::$app])
            ->onlyMethods(['requireAcceptsJson', 'requirePostRequest', 'requireVolumePermissionByFolder'])
            ->getMock();
        $controller->expects($this->once())
            ->method('requireVolumePermissionByFolder')
            ->with('saveAssets', $folder)
            ->willThrowException(new ForbiddenHttpException());

        $this->expectException(ForbiddenHttpException::class);

        try {
            $controller->actionGetUploadUrl();
        } finally {
            Craft::$app->set('assets', $assets);
            $request->setBodyParams($bodyParams);
        }
    }

    public function testGetUploadUrlRequiresReplacementPermissions(): void
    {
        $assets = Craft::$app->getAssets();
        $request = Craft::$app->getRequest();
        $bodyParams = $request->getBodyParams();
        $asset = new Asset();
        $asset->folderId = 2;
        $folder = new VolumeFolder(['id' => 2]);
        $assetsMock = $this->createMock(AssetsService::class);
        $assetsMock->expects($this->once())->method('getAssetById')->with(1)->willReturn($asset);
        $assetsMock->expects($this->once())->method('findFolder')->with(['id' => 2])->willReturn($folder);
        Craft::$app->set('assets', $assetsMock);
        $request->setBodyParams(['filename' => 'test.jpg', 'assetId' => 1, 'folderId' => 1]);

        $controller = $this->getMockBuilder(AssetsController::class)
            ->setConstructorArgs(['cloud-assets', Craft::$app])
            ->onlyMethods([
                'requireAcceptsJson',
                'requirePostRequest',
                'requireVolumePermissionByAsset',
                'requirePeerVolumePermissionByAsset',
            ])
            ->getMock();
        $controller->expects($this->once())
            ->method('requireVolumePermissionByAsset')
            ->with('replaceFiles', $asset);
        $controller->expects($this->once())
            ->method('requirePeerVolumePermissionByAsset')
            ->with('replacePeerFiles', $asset)
            ->willThrowException(new ForbiddenHttpException());

        $this->expectException(ForbiddenHttpException::class);

        try {
            $controller->actionGetUploadUrl();
        } finally {
            Craft::$app->set('assets', $assets);
            $request->setBodyParams($bodyParams);
        }
    }

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
        $volume = new TestVolume(123, $fs);

        if (method_exists($volume, 'setSubpath')) {
            $volume->setSubpath('volume-prefix');
        }

        $asset = new TestAsset($volume);
        $asset->setFilename('upload.jpeg');
        $asset->kind = Asset::KIND_IMAGE;

        $controller->setUploadedAssetMetadataForTest($asset, 'upload.jpeg');

        $this->assertSame(123, $asset->size);
        $this->assertSame(4032, $asset->getWidth());
        $this->assertSame(3024, $asset->getHeight());
        $this->assertSame(1, $fs->readCount);
        $this->assertSame([method_exists($volume, 'getSubpath') ? 'volume-prefix/upload.jpeg' : 'upload.jpeg'], $fs->requestedPaths);
    }

    public function testUploadedAssetMetadataLeavesUnreadableDimensionsNull(): void
    {
        $controller = new SizeTestAssetsController('cloud-assets', Craft::$app);
        $asset = new TestAsset(new TestVolume(123, new NullDimensionsTestFs()));
        $asset->setFilename('upload.png');
        $asset->kind = Asset::KIND_IMAGE;

        $controller->setUploadedAssetMetadataForTest($asset, 'upload.png');

        $this->assertSame(123, $asset->size);
        $this->assertNull($asset->getWidth());
        $this->assertNull($asset->getHeight());
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

    public function testUploadedImageDimensionsStopAfterBoundedRanges(): void
    {
        $fs = new FullStreamHeaderTestFs();
        $fs->headers = array_fill(0, 4, "\xFF\xD8" . "\xFF\xE1" . pack('n', 4) . 'xx');
        $fs->streamHeader = "\xFF\xD8"
            . "\xFF\xE1" . pack('n', 4) . 'xx'
            . "\xFF\xC0" . pack('n', 17) . "\x08" . pack('n', 3024) . pack('n', 4032) . str_repeat("\0", 10);

        $this->assertNull($fs->getImageDimensions('upload.jpeg'));
        $this->assertSame(4, $fs->readCount);
        $this->assertSame(0, $fs->streamReadCount);
    }

    public function testCliAssetsRepairRoutesResolveThroughYiiModule(): void
    {
        $module = new BaseModule('cloud');
        $module->controllerNamespace = 'craft\\cloud\\cli\\controllers';

        $this->assertCliRoute($module, 'assets/repair', RepairController::class, '', 'missing');
        $this->assertCliRoute($module, 'assets/repair/missing', RepairController::class, 'missing');
        $this->assertCliRoute($module, 'assets/repair/metadata', RepairController::class, 'metadata');
        $this->assertCliRoute($module, 'assets/replace-metadata', ReplaceMetadataController::class, '', 'index');
    }

    public function testCliAssetsCommandsDoNotExposeTopLevelOrUnreleasedRoutes(): void
    {
        $module = new BaseModule('cloud');
        $module->controllerNamespace = 'craft\\cloud\\cli\\controllers';

        $this->assertFalse($module->createController('assets'));
        $this->assertFalse($module->createController('assets/repair-metadata'));
    }

    public function testCliAssetsCommandsExposeAssetFilters(): void
    {
        $repairController = new RepairController('assets/repair', Craft::$app);
        $replaceMetadataController = new ReplaceMetadataController('assets/replace-metadata', Craft::$app);

        $this->assertCliAssetFilterOptions($repairController, 'missing');
        $this->assertCliAssetFilterOptions($repairController, 'metadata');
        $this->assertCliAssetFilterOptions($replaceMetadataController, 'index');
    }

    public function testRepairAssetDimensionsUsesVolumeSubpath(): void
    {
        if (!method_exists(new Volume(), 'setSubpath')) {
            $this->markTestSkipped('Craft 4 volumes do not implement a subpath.');
        }

        $fs = new HeaderTestFs();
        $fs->header = "\xFF\xD8"
            . "\xFF\xE1" . pack('n', 4) . 'xx'
            . "\xFF\xC0" . pack('n', 17) . "\x08" . pack('n', 3024) . pack('n', 4032) . str_repeat("\0", 10);
        $volume = new TestVolume(123, $fs);
        $volume->setSubpath('volume-prefix');
        $asset = new TestAsset($volume);
        $asset->setFilename('upload.jpeg');
        $asset->setWidth(4032);
        $asset->setHeight(3024);

        $controller = new TestRepairController('assets/repair', Craft::$app);

        $this->assertSame([4032, 3024], $controller->repairAssetDimensionsForTest($asset));
        $this->assertSame(['volume-prefix/upload.jpeg'], $fs->requestedPaths);
    }

    private function invokeVolumeSubpath(Volume $volume): string
    {
        $controller = new AssetsController('cloud-assets', Craft::$app);
        $method = new ReflectionMethod($controller, 'volumeSubpath');
        $method->setAccessible(true);

        return $method->invoke($controller, $volume);
    }

    /**
     * @param class-string $controllerClass
     */
    private function assertCliRoute(
        BaseModule $module,
        string $route,
        string $controllerClass,
        string $actionId,
        ?string $effectiveActionId = null,
    ): void {
        $result = $module->createController($route);

        $this->assertIsArray($result);

        [$controller, $resolvedActionId] = $result;
        $this->assertInstanceOf($controllerClass, $controller);
        $this->assertSame($actionId, $resolvedActionId);

        $action = $controller->createAction($resolvedActionId);

        $this->assertNotNull($action);
        $this->assertSame($effectiveActionId ?? $actionId, $action->id);
    }

    private function assertCliAssetFilterOptions(ConsoleController $controller, string $actionId): void
    {
        $options = $controller->options($actionId);

        $this->assertContains('volume', $options);
        $this->assertContains('assetId', $options);
    }
}

class SizeTestAssetsController extends AssetsController
{
    public function setUploadedAssetMetadataForTest(Asset $asset, string $filename, ?string $displayFilename = null): void
    {
        $this->setUploadedAssetMetadata($asset, $filename, $displayFilename);
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
    public array $requestedPaths = [];

    public static function displayName(): string
    {
        return 'Header Test';
    }

    public function getFileStreamRange(string $uriPath, int $start, int $end)
    {
        $this->readCount++;
        $this->requestedPaths[] = $uriPath;

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $this->headers[$this->readCount - 1] ?? $this->header);
        rewind($stream);

        return $stream;
    }
}

class FullStreamHeaderTestFs extends HeaderTestFs
{
    public ?string $streamHeader = null;
    public int $streamReadCount = 0;

    public function getFileStream(string $uriPath)
    {
        $this->streamReadCount++;

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $this->streamHeader ?? $this->header);
        rewind($stream);

        return $stream;
    }
}

class NullDimensionsTestFs extends HeaderTestFs
{
    public function getImageDimensions(string $uriPath): ?array
    {
        return null;
    }
}

class TestRepairController extends RepairController
{
    public function repairAssetDimensionsForTest(Asset $asset): ?array
    {
        return $this->repairAssetDimensions($asset);
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
