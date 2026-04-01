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

    public function testCropModeWithExplicitGravityModePreservesItInOptions(): void
    {
        $transform = new ImageTransform([
            'mode' => 'crop',
            'width' => 1200,
            'height' => 750,
            'gravity' => [
                'x' => 0.57,
                'y' => 0.7707,
                'mode' => 'box-center',
            ],
        ]);

        $this->assertSame([
            'fit' => 'cover',
            'gravity' => [
                'x' => 0.57,
                'y' => 0.7707,
                'mode' => 'box-center',
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

    public function testFocalPointCropUsesBoxCenteredGravity(): void
    {
        $asset = $this->makeAssetStub(true, ['x' => 0.57, 'y' => 0.7707]);
        $transform = new ImageTransform([
            'mode' => 'crop',
            'width' => 1200,
            'height' => 750,
            'position' => 'top-center',
        ]);

        (new TestImageTransformer())->applyFocalPointGravity($asset, $transform);

        $this->assertSame([
            'x' => 0.57,
            'y' => 0.7707,
            'mode' => 'box-center',
        ], $transform->gravity);
    }

    private function makeAssetStub(bool $hasFocalPoint, array $focalPoint): Asset
    {
        return new class($hasFocalPoint, $focalPoint) extends Asset {
            public function __construct(private bool $hasFocalPoint, private array $focalPoint)
            {
                parent::__construct();
            }

            public function getHasFocalPoint(): bool
            {
                return $this->hasFocalPoint;
            }

            public function getFocalPoint(bool $asCss = false): array|string|null
            {
                if ($asCss) {
                    return ($this->focalPoint['x'] * 100) . '% ' . ($this->focalPoint['y'] * 100) . '%';
                }

                return $this->focalPoint;
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
