<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\imagetransforms\ImageTransform;
use craft\cloud\imagetransforms\ImageTransformer;
use craft\elements\Asset;

class ImageTransformTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

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
        ], $transform->toOptions());
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
        ], $transform->toOptions());
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

        (new TestImageTransformer())->applyFocalPointGravity($asset, $transform);

        $this->assertSame([
            'x' => 0.474,
            'y' => 0.3064,
        ], $transform->gravity);
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
        return new class($id, $filename, $width, $height, $focalPoint) extends Asset {
            public function __construct(
                int $id,
                private string $filenameValue,
                private int $widthValue,
                private int $heightValue,
                private array $focalPointValue,
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

            public function getWidth(array|string|\craft\models\ImageTransform $transform = null): ?int
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
        };
    }

}

class TestImageTransformer extends ImageTransformer
{
    public function applyFocalPointGravity(Asset $asset, ImageTransform $imageTransform): void
    {
        $this->applyAssetFocalPointGravity($asset, $imageTransform);
    }
}

class UrlTestImageTransformer extends ImageTransformer
{
    public function buildTransformQuery(Asset $asset, \craft\models\ImageTransform $imageTransform): string
    {
        $imageTransform = \Craft::createObject(ImageTransform::class, [$imageTransform->toArray()]);
        $this->applyAssetFocalPointGravity($asset, $imageTransform);

        return (string) \League\Uri\Components\Query::fromVariable($imageTransform->toOptions());
    }
}
