<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\base\FsInterface;
use craft\base\MemoizableArray;
use craft\cloud\fs\AssetsFs;
use craft\cloud\imagetransforms\ImageTransformBehavior;
use craft\cloud\imagetransforms\ImageTransformer;
use craft\cloud\Module as CloudModule;
use craft\cloud\signing\UrlSigner;
use craft\cloud\twig\CloudVariable;
use craft\elements\Asset;
use craft\events\GenerateTransformEvent;
use craft\fs\Local;
use craft\helpers\Template;
use craft\models\ImageTransform;
use craft\models\Volume;
use League\Uri\Components\Query;
use ReflectionProperty;
use Twig\Markup;
use yii\base\NotSupportedException;

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

        $firstAsset = $this->makeTransformUrlAsset('first.jpg', ['x' => 0.57, 'y' => 0.7707]);
        $secondAsset = $this->makeTransformUrlAsset('second.jpg', ['x' => 0.4631, 'y' => 0.308]);

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
        $asset = $this->makeTransformUrlAsset('test.jpg', ['x' => 0.5, 'y' => 0.5]);
        $transform = new ImageTransform(['width' => 100, 'height' => 100]);

        $signedUrl = $transformer->getTransformUrl($asset, $transform, true);

        $this->assertStringContainsString('&s=', $signedUrl);
        $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($signedUrl));
    }

    public function testCloudTransformUrlRemovesAssetRevBeforeSigning(): void
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $revAssetUrls = $generalConfig->revAssetUrls;

        try {
            $generalConfig->revAssetUrls = true;

            $transformer = new UrlTestImageTransformer();
            $asset = $this->makeTransformUrlAsset('test image.jpg', ['x' => 0.5, 'y' => 0.5]);
            $transform = new ImageTransform(['width' => 100, 'height' => 100]);

            $signedUrl = $transformer->getTransformUrl($asset, $transform, true);
            $parameters = Query::fromUri($signedUrl)->parameters();

            $this->assertStringNotContainsString('?&', $signedUrl);
            $this->assertArrayNotHasKey('v', $parameters);
            $this->assertSame('100', $parameters['width']);
            $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($signedUrl));
            $this->assertFalse(CloudModule::getInstance()->getUrlSigner()->verify("{$signedUrl}&v=123"));
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
        $localEvent = new GenerateTransformEvent([
            'asset' => $this->makeTransformUrlAsset('local.jpg', ['x' => 0.5, 'y' => 0.5], true),
            'transform' => new ImageTransform(['width' => 100]),
        ]);
        $request = Craft::$app->getRequest();
        $isActionRequest = $request->getIsActionRequest();
        $actionSegments = $request->getActionSegments();

        try {
            $this->assertFalse($module->usesAssetCdnTransform($localEvent));

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

    public function testPdfThumbUrlUsesSignedRasterTransform(): void
    {
        $transformer = new ImageTransformer();

        $signedUrl = $transformer->getTransformUrl(
            $this->makePdfAssetStub(),
            new ImageTransform([
                'width' => 320,
                'height' => 320,
                'mode' => 'crop',
            ]),
            true,
        );
        $parameters = Query::fromUri($signedUrl)->parameters();

        $this->assertStringContainsString('/tests/document.pdf?', $signedUrl);
        $this->assertSame('auto', $parameters['format']);
        $this->assertSame('320', $parameters['width']);
        $this->assertSame('320', $parameters['height']);
        $this->assertArrayNotHasKey('page', $parameters);
        $this->assertSame('cover', $parameters['fit']);
        $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($signedUrl));
    }

    public function testPdfTransformUrlUsesSignedRasterTransform(): void
    {
        $transformer = new ImageTransformer();

        $signedUrl = $transformer->getTransformUrl(
            $this->makePdfAssetStub(),
            [
                'width' => 640.4,
                'height' => 480.4,
                'format' => 'webp',
                'mode' => 'fit',
                'page' => '2',
                'upscale' => false,
            ],
            true,
        );
        $parameters = Query::fromUri($signedUrl)->parameters();

        $this->assertStringContainsString('/tests/document.pdf?', $signedUrl);
        $this->assertSame('webp', $parameters['format']);
        $this->assertSame('640', $parameters['width']);
        $this->assertSame('480', $parameters['height']);
        $this->assertSame('scale-down', $parameters['fit']);
        $this->assertSame('2', $parameters['page']);
        $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($signedUrl));
    }

    public function testPdfTransformUrlPreservesPageWhenExtendingNamedTransform(): void
    {
        $imageTransforms = Craft::$app->getImageTransforms();
        $transformsProperty = new ReflectionProperty($imageTransforms, '_transforms');
        $transformsProperty->setAccessible(true);
        $previousTransforms = $transformsProperty->getValue($imageTransforms);

        try {
            $transformsProperty->setValue($imageTransforms, new MemoizableArray([
                new ImageTransform([
                    'name' => 'Thumb',
                    'handle' => 'thumb',
                    'width' => 320,
                    'height' => 320,
                    'mode' => 'crop',
                ]),
            ]));

            $signedUrl = (new ImageTransformer())->getTransformUrl(
                $this->makePdfAssetStub(),
                [
                    'transform' => 'thumb',
                    'page' => '2',
                    'zoom' => 1.25,
                ],
                true,
            );

            $invalidPageUrl = (new ImageTransformer())->getTransformUrl(
                $this->makePdfAssetStub(),
                [
                    'transform' => 'thumb',
                    'page' => '1.5',
                ],
                true,
            );

            $zeroPageUrl = (new ImageTransformer())->getTransformUrl(
                $this->makePdfAssetStub(),
                [
                    'transform' => 'thumb',
                    'page' => '0',
                ],
                true,
            );
        } finally {
            $transformsProperty->setValue($imageTransforms, $previousTransforms);
        }

        $parameters = Query::fromUri($signedUrl)->parameters();
        $invalidPageParameters = Query::fromUri($invalidPageUrl)->parameters();
        $zeroPageParameters = Query::fromUri($zeroPageUrl)->parameters();

        $this->assertSame('320', $parameters['width']);
        $this->assertSame('320', $parameters['height']);
        $this->assertSame('2', $parameters['page']);
        $this->assertSame('1.25', $parameters['zoom']);
        $this->assertArrayNotHasKey('page', $invalidPageParameters);
        $this->assertArrayNotHasKey('page', $zeroPageParameters);
        $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($signedUrl));
        $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($invalidPageUrl));
        $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($zeroPageUrl));
    }

    public function testCloudGetImgDelegatesToNativeImageRendering(): void
    {
        $asset = new DelegatingImageAsset();
        $transform = ['width' => 100];
        $sizes = ['1x', '2x'];

        $img = (new CloudVariable())->getImg($asset, $transform, $sizes);

        $this->assertSame('<img src="delegated">', (string) $img);
        $this->assertSame($transform, $asset->receivedTransform);
        $this->assertSame($sizes, $asset->receivedSizes);
    }

    public function testCloudGetImgRendersSignedPdfTransform(): void
    {
        $transform = [
            'page' => '1',
            'width' => 320,
            'height' => 240,
        ];

        $img = (new CloudVariable())->getImg(new SignedPdfImageAsset(), $transform);
        $src = $this->imgSrc($img);
        $parameters = Query::fromUri($src)->parameters();

        $this->assertStringStartsWith('<img ', (string) $img);
        $this->assertStringContainsString('width="320"', (string) $img);
        $this->assertStringContainsString('height="240"', (string) $img);
        $this->assertStringContainsString('alt="document.pdf"', (string) $img);
        $this->assertStringContainsString('/tests/document.pdf?', $src);
        $this->assertSame('auto', $parameters['format']);
        $this->assertSame('1', $parameters['page']);
        $this->assertTrue(CloudModule::getInstance()->getUrlSigner()->verify($src));
    }

    public function testCloudGetImgRequiresPdfTransform(): void
    {
        $this->assertNull((new CloudVariable())->getImg(new UnsignedPdfImageAsset()));
    }

    public function testCloudGetImgDoesNotRenderUnsignedPdfUrl(): void
    {
        $this->assertNull((new CloudVariable())->getImg(new UnsignedPdfImageAsset(), ['page' => 1]));
    }

    public function testPdfTransformsRequireCloudAssets(): void
    {
        $transformer = new ImageTransformer();

        $this->expectException(NotSupportedException::class);

        $transformer->getTransformUrl($this->makePdfAssetStub(false), ['width' => 100], true);
    }

    public function testPdfTransformsRequireRemoteCloudAssets(): void
    {
        $transformer = new ImageTransformer();

        $this->expectException(NotSupportedException::class);

        $transformer->getTransformUrl($this->makePdfAssetStub(true, true), ['width' => 100], true);
    }

    public function testLocalCloudFsIsNotSignedByCloudTransformer(): void
    {
        $transformer = new ImageTransformer();

        $this->expectException(NotSupportedException::class);

        $transformer->getTransformUrl(
            $this->makeTransformUrlAsset('local.jpg', ['x' => 0.5, 'y' => 0.5], true),
            new ImageTransform(['width' => 100]),
            true,
        );
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

    private function makeTransformUrlAsset(string $filename, array $focalPoint, bool $useLocalFs = false): Asset
    {
        return new TransformUrlAsset($filename, $focalPoint, $useLocalFs);
    }

    private function makePdfAssetStub(bool $useAssetCdn = true, bool $useLocalFs = false): Asset
    {
        return new class($useAssetCdn, $useLocalFs) extends Asset {
            public function __construct(
                private bool $useAssetCdn,
                private bool $useLocalFs,
            ) {
                parent::__construct();
                $this->kind = self::KIND_PDF;
                $this->folderPath = 'tests/';
            }

            public function getFilename(bool $withExtension = true): string
            {
                return 'document.pdf';
            }

            public function getPath(?string $filename = null): string
            {
                return 'tests/' . ($filename ?? 'document.pdf');
            }

            public function getVolume(): Volume
            {
                return new class($this->useAssetCdn, $this->useLocalFs) extends Volume {
                    public function __construct(
                        private bool $useAssetCdn,
                        private bool $useLocalFs,
                    ) {
                        parent::__construct();
                    }

                    public function getFs(): FsInterface
                    {
                        return $this->useAssetCdn
                            ? new class($this->useLocalFs) extends AssetsFs {
                                public function __construct(private bool $assetUseLocalFs)
                                {
                                    parent::__construct();
                                }

                                public function init(): void
                                {
                                    parent::init();
                                    $this->useLocalFs = $this->assetUseLocalFs;
                                }
                            }
                        : new Local(['path' => Craft::getAlias('@runtime')]);
                    }

                    public function getTransformFs(): FsInterface
                    {
                        return $this->getFs();
                    }

                    public function getRootUrl(): ?string
                    {
                        return 'https://cdn.craft.cloud/test-environment/assets/';
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

    private function imgSrc(?Markup $img): string
    {
        $this->assertNotNull($img);
        $this->assertSame(1, preg_match('/\bsrc="([^"]+)"/', (string) $img, $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
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
                return new class() extends AssetsFs {
                    public function init(): void
                    {
                        parent::init();
                        $this->useLocalFs = false;
                    }
                };
            }
        };
    }
}

class DelegatingImageAsset extends Asset
{
    public mixed $receivedTransform = null;
    public ?array $receivedSizes = null;

    public function __construct()
    {
        parent::__construct();
        $this->kind = self::KIND_IMAGE;
    }

    public function getImg(mixed $transform = null, ?array $sizes = null): ?Markup
    {
        $this->receivedTransform = $transform;
        $this->receivedSizes = $sizes;

        return Template::raw('<img src="delegated">');
    }
}

class SignedPdfImageAsset extends Asset
{
    public function __construct()
    {
        parent::__construct();
        $this->kind = self::KIND_PDF;
        $this->folderPath = 'tests/';
    }

    public function getFilename(bool $withExtension = true): string
    {
        return 'document.pdf';
    }

    public function getPath(?string $filename = null): string
    {
        return 'tests/' . ($filename ?? 'document.pdf');
    }

    public function getUrl(mixed $transform = null, ?bool $immediately = null): ?string
    {
        return (new ImageTransformer())->getTransformUrl($this, $transform, true);
    }

    public function getVolume(): Volume
    {
        return new Volume([
            'fs' => new class() extends AssetsFs {
                public function init(): void
                {
                    parent::init();
                    $this->useLocalFs = false;
                }

                public function getRootUrl(): ?string
                {
                    return 'https://cdn.craft.cloud/assets/';
                }
            },
        ]);
    }
}

class UnsignedPdfImageAsset extends Asset
{
    public function __construct()
    {
        parent::__construct();
        $this->kind = self::KIND_PDF;
    }

    public function getUrl(mixed $transform = null, ?bool $immediately = null): ?string
    {
        return 'https://cdn.craft.cloud/assets/document.pdf?page=1&format=auto';
    }
}

class TransformUrlAsset extends Asset
{
    public function __construct(
        private string $filenameValue,
        private array $focalPointValue,
        private bool $useLocalFs = false,
    ) {
        parent::__construct();
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
        return new Volume([
            'fs' => new class($this->useLocalFs) extends AssetsFs {
                public function __construct(private bool $assetUseLocalFs)
                {
                    parent::__construct();
                }

                public function init(): void
                {
                    parent::init();
                    $this->useLocalFs = $this->assetUseLocalFs;
                }

                public function getRootUrl(): ?string
                {
                    return 'https://cdn.craft.cloud/assets/';
                }
            },
        ]);
    }
}
