<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\fs\AssetsFs;
use craft\cloud\imagetransforms\ImageTransformBehavior;
use craft\cloud\imagetransforms\ImageTransformer;
use craft\cloud\Module as CloudModule;
use craft\cloud\signing\UrlSigner;
use craft\elements\Asset;
use craft\events\GenerateTransformEvent;
use craft\models\ImageTransform;
use craft\models\Volume;
use League\Uri\Components\Query;
use ReflectionProperty;

class ImageTransformTest extends Unit
{
    protected function _before(): void
    {
        parent::_before();

        if (CloudModule::getInstance() === null) {
            $module = new CloudModule('cloud');
            $module->bootstrap(Craft::$app);
        }

        CloudModule::getInstance()->set('urlSigner', fn() => new UrlSigner('test-signing-key'));
    }

    public function testCropModeWithExplicitGravityPreservesItInOptions(): void
    {
        $transform = new ImageTransform([
            'mode' => 'crop',
            'width' => 1200,
            'height' => 750,
            'gravity' => [
                'x' => 0.57,
                'y' => 0.7707,
            ],
        ]);

        $this->assertSame([
            'fit' => 'cover',
            'gravity' => [
                'x' => 0.57,
                'y' => 0.7707,
            ],
            'height' => 750,
            'width' => 1200,
        ], $this->behavior($transform)->toOptions());
    }

    public function testCropModeWithoutGravityUsesPositionMapping(): void
    {
        $transform = new ImageTransform([
            'mode' => 'crop',
            'position' => 'top-center',
            'width' => 1200,
            'height' => 750,
        ]);

        $this->assertSame([
            'fit' => 'cover',
            'gravity' => [
                'x' => 0.5,
                'y' => 0,
            ],
            'height' => 750,
            'width' => 1200,
        ], $this->behavior($transform)->toOptions());
    }

    public function testFocalPointGravityPassesThroughUnchanged(): void
    {
        $asset = $this->makeAssetStub(['x' => 0.474, 'y' => 0.3064]);
        $transform = new ImageTransform([
            'mode' => 'crop',
            'position' => 'top-center',
            'width' => 1200,
            'height' => 750,
        ]);

        $gravity = (new TestImageTransformer())->applyFocalPointGravity($asset, $transform);

        $this->assertSame([
            'x' => 0.474,
            'y' => 0.3064,
        ], $gravity);
        $this->assertNull($this->behavior($transform)->gravity);
    }

    public function testInlineCloudPropertyDoesNotBreakBaseTransform(): void
    {
        $transform = new ImageTransform([
            'width' => 200,
            'blur' => 5,
        ]);

        $this->assertSame(5, $this->behavior($transform)->blur);
        $this->assertSame([
            'blur' => 5,
            'fit' => 'cover',
            'width' => 200,
        ], $this->behavior($transform)->toOptions());
    }

    public function testGetTransformUrlDoesNotLeakGravityBetweenAssets(): void
    {
        $transform = new ImageTransform([
            'mode' => 'crop',
            'position' => 'top-center',
            'width' => 1200,
            'height' => 750,
        ]);

        $firstAsset = $this->makeUrlAssetStub(1, 'first.jpg', 3402, 4253, ['x' => 0.57, 'y' => 0.7707]);
        $secondAsset = $this->makeUrlAssetStub(2, 'second.jpg', 3402, 4253, ['x' => 0.4631, 'y' => 0.308]);

        $transformer = new UrlTestImageTransformer();

        $firstUrl = $transformer->buildTransformQuery($firstAsset, $transform);
        $secondUrl = $transformer->buildTransformQuery($secondAsset, $transform);

        $this->assertStringContainsString('gravity%5Bx%5D=0.57', $firstUrl);
        $this->assertStringContainsString('gravity%5By%5D=0.7707', $firstUrl);
        $this->assertStringContainsString('gravity%5Bx%5D=0.4631', $secondUrl);
        $this->assertStringContainsString('gravity%5By%5D=0.308', $secondUrl);
        $this->assertStringNotContainsString('gravity%5Bx%5D=0.57', $secondUrl);
    }

    public function testTransformUrlSigningUsesSharedUrlSigner(): void
    {
        $transformer = new UrlTestImageTransformer();
        $asset = $this->makeUrlAssetStub(1, 'test.jpg', 100, 100, ['x' => 0.5, 'y' => 0.5]);
        $transform = new ImageTransform(['width' => 100, 'height' => 100]);

        $signedUrl = $transformer->getTransformUrl($asset, $transform, true);

        $this->assertStringContainsString('&s=', $signedUrl);
        $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($signedUrl));
    }

    public function testCloudTransformUrlDoesNotAppendAssetRevAfterSigning(): void
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $revAssetUrls = $generalConfig->revAssetUrls;

        try {
            $generalConfig->revAssetUrls = true;

            $transformer = new UrlTestImageTransformer();
            $asset = $this->makeUrlAssetStub(1, 'test image.jpg', 100, 100, ['x' => 0.5, 'y' => 0.5]);
            $transform = new ImageTransform(['width' => 100, 'height' => 100]);

            $signedUrl = $transformer->getTransformUrl($asset, $transform, true);
            $parameters = Query::fromUri($signedUrl)->parameters();

            $this->assertStringNotContainsString('?&', $signedUrl);
            $this->assertArrayNotHasKey('v', $parameters);
            $this->assertSame('100', $parameters['height']);
            $this->assertSame('100', $parameters['width']);
            $this->assertArrayHasKey('s', $parameters);
            $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($signedUrl));
            $this->assertFalse(CloudModule::getInstance()->getUrlSigner()->verify("{$signedUrl}&v=123"));
            $this->assertTrue($generalConfig->revAssetUrls);
        } finally {
            $generalConfig->revAssetUrls = $revAssetUrls;
        }
    }

    public function testEditImageActionUsesNativeTransforms(): void
    {
        $module = new TestCloudModule('cloud-test');
        $event = new GenerateTransformEvent([
            'asset' => new TransformDecisionAsset(),
            'transform' => new ImageTransform(['width' => 100]),
        ]);
        $request = Craft::$app->getRequest();
        $isActionRequest = $request->getIsActionRequest();
        $actionSegments = $request->getActionSegments();

        try {
            $request->setIsActionRequest(true);
            $this->setActionSegments(['assets', 'edit-image']);
            $this->assertFalse($module->usesAssetCdnTransform($event));

            $this->setActionSegments(['assets', 'generate-transform']);
            $this->assertTrue($module->usesAssetCdnTransform($event));

            $request->setIsActionRequest(false);
            $this->setActionSegments(null);
            $this->assertTrue($module->usesAssetCdnTransform($event));
        } finally {
            $request->setIsActionRequest($isActionRequest);
            $this->setActionSegments($actionSegments);
        }
    }

    public function testSupportedInputFormatsMatchCloudflareImages(): void
    {
        $this->assertSame([
            'png',
            'jpg',
            'jpeg',
            'gif',
            'webp',
            'svg',
            'avif',
            'heic',
        ], ImageTransformer::SUPPORTED_IMAGE_FORMATS);
    }

    private function setActionSegments(?array $actionSegments): void
    {
        $property = new ReflectionProperty(Craft::$app->getRequest(), '_actionSegments');
        $property->setAccessible(true);
        $property->setValue(Craft::$app->getRequest(), $actionSegments);
    }

    private function makeAssetStub(array $focalPoint): Asset
    {
        return new class($focalPoint) extends Asset {
            public function __construct(private array $focalPointValue)
            {
                parent::__construct();
            }

            public function getHasFocalPoint(): bool
            {
                return true;
            }

            public function getFocalPoint(bool $asCss = false): array|string|null
            {
                if ($asCss) {
                    return ($this->focalPointValue['x'] * 100) . '% ' . ($this->focalPointValue['y'] * 100) . '%';
                }

                return $this->focalPointValue;
            }
        };
    }

    private function makeUrlAssetStub(int $id, string $filename, int $width, int $height, array $focalPoint): Asset
    {
        return new class($id, $filename, $width, $height, $focalPoint, $this->makeCloudAssetsVolume()) extends Asset {
            public function __construct(
                int $id,
                private string $filenameValue,
                private int $widthValue,
                private int $heightValue,
                private array $focalPointValue,
                private Volume $volumeValue,
            ) {
                parent::__construct();
                $this->id = $id;
                $this->kind = self::KIND_IMAGE;
            }

            public function getHasFocalPoint(): bool
            {
                return true;
            }

            public function getFocalPoint(bool $asCss = false): array|string|null
            {
                if ($asCss) {
                    return ($this->focalPointValue['x'] * 100) . '% ' . ($this->focalPointValue['y'] * 100) . '%';
                }

                return $this->focalPointValue;
            }

            public function getWidth(array|string|\craft\models\ImageTransform|null $transform = null): ?int
            {
                return $this->widthValue;
            }

            public function getHeight(mixed $transform = null): ?int
            {
                return $this->heightValue;
            }

            public function getFilename(bool $withExtension = true): string
            {
                return $this->filenameValue;
            }

            public function getPath(?string $filename = null): string
            {
                return 'tests/' . ($filename ?? $this->filenameValue);
            }

            public function getMimeType(mixed $transform = null): ?string
            {
                return 'image/jpeg';
            }

            public function getVolume(): Volume
            {
                return $this->volumeValue;
            }
        };
    }

    private function makeCloudAssetsVolume(): Volume
    {
        return new class() extends Volume {
            public function getFs(): AssetsFs
            {
                return new class() extends AssetsFs {
                    public function init(): void
                    {
                        parent::init();
                        $this->useLocalFs = false;
                    }

                    public function getRootUrl(): ?string
                    {
                        return 'https://cdn.craft.cloud/assets/';
                    }
                };
            }
        };
    }

    private function behavior(ImageTransform $transform): ImageTransformBehavior
    {
        $behavior = $transform->getBehavior('cloud');

        $this->assertInstanceOf(ImageTransformBehavior::class, $behavior);

        return $behavior;
    }
}

class TestImageTransformer extends ImageTransformer
{
    public function applyFocalPointGravity(Asset $asset, ImageTransform $imageTransform): array|string|null
    {
        return $this->applyAssetFocalPointGravity($asset, $imageTransform);
    }
}

class UrlTestImageTransformer extends ImageTransformer
{
    public function buildTransformQuery(Asset $asset, ImageTransform $imageTransform): string
    {
        $gravity = $this->applyAssetFocalPointGravity($asset, $imageTransform);

        /** @var ImageTransformBehavior $behavior */
        $behavior = $imageTransform->getBehavior('cloud');

        return (string) \League\Uri\Components\Query::fromVariable($behavior->toOptions($gravity));
    }
}

class TestCloudModule extends CloudModule
{
    public function usesAssetCdnTransform(GenerateTransformEvent $event): bool
    {
        return $this->shouldUseAssetCdnTransform($event);
    }
}

class TransformDecisionAsset extends Asset
{
    public function getVolume(): Volume
    {
        return new class() extends Volume {
            public function getFs(): AssetsFs
            {
                return new AssetsFs();
            }
        };
    }
}
