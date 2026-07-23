<?php

namespace craft\cloud\imagetransforms;

use Craft;
use craft\cloud\fs\AssetsFs;
use craft\elements\Asset;
use craft\events\SaveAssetImageEvent;
use craft\helpers\StringHelper;
use craft\models\ImageTransform;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use yii\base\NotSupportedException;
use yii\web\BadRequestHttpException;

class ImageEditor
{
    public function handleSaveImage(SaveAssetImageEvent $event): void
    {
        $asset = $event->asset ?? null;

        if (!$asset instanceof Asset || !$asset->getVolume()->getFs() instanceof AssetsFs) {
            return;
        }

        if (!in_array($this->rotation($event->viewportRotation, $event->imageRotation), [0, 90, 180, 270], true)) {
            return;
        }

        $event->handled = true;

        try {
            $editedAsset = $this->editImage(
                $asset,
                $event->replace,
                $event->viewportRotation,
                $event->imageRotation,
                $event->cropData,
                $event->focalPoint,
                $event->imageDimensions,
                $event->flipData,
                $event->zoom,
            );
        } catch (NotSupportedException $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $message = trim((string)$e->getResponse()?->getBody()) ?: $e->getMessage();

            throw new BadRequestHttpException($message, 0, $e);
        }

        $event->newAssetId = $event->replace ? null : $editedAsset->id;
    }

    public function editImage(
        Asset $asset,
        bool $replace,
        int $viewportRotation,
        float $imageRotation,
        array $cropData,
        ?array $focalPoint,
        array $imageDimensions,
        ?array $flipData,
        float $zoom,
    ): Asset {
        $focal = $this->focalPoint($asset, $focalPoint, $viewportRotation, $imageRotation, $cropData, $imageDimensions, $flipData, $zoom);
        $transform = ImageTransformer::fromImageEditor($asset, $viewportRotation, $imageRotation, $cropData, $imageDimensions, $flipData, $zoom, $focalPoint);

        if ($transform === null && !$this->focalPointChanged($asset, $focal)) {
            return $replace ? $asset : $this->createAsset($asset, null, $focal);
        }

        if ($replace) {
            $this->replaceAsset($asset, $transform, $focal);

            return $asset;
        }

        return $this->createAsset($asset, $transform, $focal);
    }

    protected function focalPoint(Asset $asset, ?array $focalPoint, int $viewportRotation, float $imageRotation, array $cropData, array $imageDimensions, ?array $flipData, float $zoom): ?array
    {
        if (!$focalPoint) {
            return null;
        }

        if (!$asset->width || !$asset->height) {
            throw new NotSupportedException('Image dimensions are required to edit images.');
        }

        $rotation = $this->rotation($viewportRotation, $imageRotation);
        $crop = $this->crop($asset, $rotation, $cropData, $imageDimensions, $zoom);
        $editedDimensions = $this->rotatedDimensions($asset->width, $asset->height, $rotation);
        $adjustmentRatio = min(
            $asset->width / $focalPoint['imageDimensions']['width'],
            $asset->height / $focalPoint['imageDimensions']['height'],
        );

        $focal = [
            'x' => (($editedDimensions['width'] / 2) + ($focalPoint['offsetX'] * $zoom * $adjustmentRatio) - $crop['left']) / $crop['width'],
            'y' => (($editedDimensions['height'] / 2) + ($focalPoint['offsetY'] * $zoom * $adjustmentRatio) - $crop['top']) / $crop['height'],
        ];

        if (!empty($flipData['x'])) {
            $focal['x'] = 1 - $focal['x'];
        }

        if (!empty($flipData['y'])) {
            $focal['y'] = 1 - $focal['y'];
        }

        return $focal;
    }

    protected function crop(Asset $asset, int $rotation, array $cropData, array $imageDimensions, float $zoom): array
    {
        $adjustmentRatio = min(
            $asset->width / $imageDimensions['width'],
            $asset->height / $imageDimensions['height'],
        );
        $editedDimensions = $this->rotatedDimensions($asset->width, $asset->height, $rotation);

        $width = (int)round($cropData['width'] * $zoom * $adjustmentRatio);
        $height = (int)round($cropData['height'] * $zoom * $adjustmentRatio);

        return [
            'left' => (int)round(($editedDimensions['width'] / 2) + ($cropData['offsetX'] * $zoom * $adjustmentRatio) - ($width / 2)),
            'top' => (int)round(($editedDimensions['height'] / 2) + ($cropData['offsetY'] * $zoom * $adjustmentRatio) - ($height / 2)),
            'width' => $width,
            'height' => $height,
        ];
    }

    protected function rotation(int $viewportRotation, float $imageRotation): int
    {
        return ((int)round($imageRotation + $viewportRotation) % 360 + 360) % 360;
    }

    protected function rotatedDimensions(int|float $width, int|float $height, int $rotation): array
    {
        return in_array($rotation, [90, 270], true)
            ? ['width' => (int)round($height), 'height' => (int)round($width)]
            : ['width' => (int)round($width), 'height' => (int)round($height)];
    }

    protected function focalPointChanged(Asset $asset, ?array $focal): bool
    {
        $oldFocal = $asset->getHasFocalPoint() ? $asset->getFocalPoint() : null;

        return $focal !== $oldFocal;
    }

    protected function replaceAsset(Asset $asset, ?ImageTransform $transform, ?array $focal): void
    {
        $focalPointChanged = $this->focalPointChanged($asset, $focal);
        $tempPath = null;

        $asset->setFocalPoint($focal);

        if ($focalPointChanged) {
            Craft::$app->getImageTransforms()->deleteCreatedTransformsForAsset($asset);
        }

        try {
            if ($transform !== null) {
                $tempPath = $this->downloadEditedImage($asset, $transform);
                $asset->sanitizeOnUpload = false;
                Craft::$app->getAssets()->replaceAssetFile($asset, $tempPath, $asset->getFilename());
                return;
            }

            Craft::$app->getElements()->saveElement($asset);
        } finally {
            if ($tempPath !== null && file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    protected function createAsset(Asset $asset, ?ImageTransform $transform, ?array $focal): Asset
    {
        $tempPath = null;
        $newAsset = new Asset();
        $newAsset->avoidFilenameConflicts = true;
        $newAsset->setScenario(Asset::SCENARIO_CREATE);
        $newAsset->sanitizeOnUpload = false;
        $newAsset->setFilename($asset->getFilename());
        $newAsset->newFolderId = $asset->folderId;
        $newAsset->setVolumeId($asset->volumeId);
        $newAsset->setFocalPoint($focal);

        try {
            if ($transform !== null) {
                $tempPath = $this->downloadEditedImage($asset, $transform);
            }

            $newAsset->tempFilePath = $tempPath ?? $asset->getCopyOfFile();

            Craft::$app->getElements()->saveElement($newAsset);
        } finally {
            if ($tempPath !== null && file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        return $newAsset;
    }

    protected function downloadEditedImage(Asset $asset, ImageTransform $transform): string
    {
        $path = sprintf(
            '%s/%s.%s',
            Craft::$app->getPath()->getTempPath(),
            StringHelper::UUID(),
            $asset->getExtension(),
        );

        try {
            Craft::createGuzzleClient()->get((new ImageTransformer())->getTransformUrl($asset, $transform, true), [
                RequestOptions::SINK => $path,
            ]);
        } catch (\Throwable $e) {
            if (file_exists($path)) {
                unlink($path);
            }

            throw $e;
        }

        return $path;
    }
}
