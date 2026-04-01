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

    public function testCropModeMapsFocalPointToClampedCropOriginGravity(): void
    {
        $asset = $this->makeAssetStub(3402, 4253, ['x' => 0.474, 'y' => 0.3064]);
        $transform = new ImageTransform([
            'mode' => 'crop',
            'position' => 'top-center',
            'width' => 1200,
            'height' => 750,
        ]);

        (new TestImageTransformer())->applyFocalPointGravity($asset, $transform);

        $this->assertEqualsWithDelta([
            'x' => 0.474,
            'y' => 0.1128,
        ], $transform->gravity, 0.0001);
    }

    private function makeAssetStub(int $width, int $height, array $focalPoint): Asset
    {
        return new class($width, $height, $focalPoint) extends Asset {
            public function __construct(private int $widthValue, private int $heightValue, private array $focalPointValue)
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

            public function getWidth(array|string|\craft\models\ImageTransform $transform = null): ?int
            {
                return $this->widthValue;
            }

            public function getHeight(mixed $transform = null): ?int
            {
                return $this->heightValue;
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
