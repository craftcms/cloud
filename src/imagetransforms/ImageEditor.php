<?php

namespace craft\cloud\imagetransforms;

use Craft;
use craft\cloud\fs\AssetsFs;
use craft\elements\Asset;
use craft\events\SaveAssetImageEvent;
use craft\helpers\StringHelper;
use craft\models\ImageTransform;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use yii\base\NotSupportedException;
use yii\web\BadRequestHttpException;

class ImageEditor
{
    public function handleSaveImage(SaveAssetImageEvent $event): void
    {
        $asset = $event->asset ?? null;

        if (!$asset instanceof Asset || !$this->supportsEdgeEditing($asset)) {
            return;
        }

        if (!$this->hasRightAngleRotation($event->viewportRotation, $event->imageRotation)) {
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
        } catch (TransferException $e) {
            $message = $e instanceof RequestException
                ? trim((string)$e->getResponse()?->getBody())
                : '';
            $message = $message ?: 'Could not save the edited image.';

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
        $transform = $this->imageTransform($asset, $viewportRotation, $imageRotation, $cropData, $imageDimensions, $flipData, $zoom);
        $focal = $this->focalPoint($asset, $focalPoint, $viewportRotation, $imageRotation, $cropData, $imageDimensions, $flipData, $zoom);

        if ($transform !== null && $focal !== null) {
            /** @var ImageTransformBehavior $behavior */
            $behavior = $transform->getBehavior('cloud');
            $behavior->gravity = $focal;
        }

        if ($transform === null && !$this->focalPointChanged($asset, $focal)) {
            return $replace ? $asset : $this->createAsset($asset, null, $focal);
        }

        if ($replace) {
            $this->replaceAsset($asset, $transform, $focal);

            return $asset;
        }

        return $this->createAsset($asset, $transform, $focal);
    }

    protected function imageTransform(
        Asset $asset,
        int $viewportRotation,
        float $imageRotation,
        array $cropData,
        array $imageDimensions,
        ?array $flipData,
        float $zoom,
    ): ?ImageTransform {
        $rotation = $this->rotation($viewportRotation, $imageRotation);
        $flipX = !empty($flipData['x']);
        $flipY = !empty($flipData['y']);
        $flip = match (true) {
            $flipX && $flipY => 'hv',
            $flipX => 'h',
            $flipY => 'v',
            default => null,
        };

        $this->ensureImageEditorData($cropData, $imageDimensions, $zoom);

        $sourceReferenceDimensions = $this->rotatedDimensions($imageDimensions['width'], $imageDimensions['height'], 0);
        $cropReferenceDimensions = $this->rotatedDimensions($imageDimensions['width'], $imageDimensions['height'], $rotation);
        $cropDimensions = [
            'width' => (int)round($cropData['width']),
            'height' => (int)round($cropData['height']),
        ];
        $imageCropped = ($cropDimensions !== $sourceReferenceDimensions && $cropDimensions !== $cropReferenceDimensions) ||
            $zoom !== 1.0 ||
            (float)$cropData['offsetX'] !== 0.0 ||
            (float)$cropData['offsetY'] !== 0.0;
        $imageRotated = $rotation !== 0;
        $imageFlipped = $flip !== null;

        if (!$imageCropped && !$imageRotated && !$imageFlipped) {
            return null;
        }

        if (!$this->hasRightAngleRotation($viewportRotation, $imageRotation)) {
            throw new NotSupportedException('Only 90-degree image rotations are supported.');
        }

        if (!$asset->width || !$asset->height) {
            throw new NotSupportedException('Image dimensions are required to edit images.');
        }

        $transform = new ImageTransform([
            'width' => $asset->width,
            'height' => $asset->height,
        ]);

        /** @var ImageTransformBehavior $behavior */
        $behavior = $transform->getBehavior('cloud');
        $behavior->fit = 'crop';

        if ($zoom !== 1.0) {
            $behavior->zoom = max(0, min(1, 1 - (1 / $zoom)));
        }

        if ($imageCropped) {
            $crop = $this->constrainCrop(
                $this->sourceCrop($this->crop($asset, $rotation, $cropData, $imageDimensions, $zoom), $asset->width, $asset->height, $rotation),
                $asset->width,
                $asset->height,
            );

            $transform->width = $crop['width'];
            $transform->height = $crop['height'];
            $behavior->trim = $crop;
        }

        if ($imageRotated) {
            $behavior->rotate = $rotation;

            if (in_array($rotation, [90, 270], true)) {
                [$transform->width, $transform->height] = [$transform->height, $transform->width];
            }
        }

        if ($imageFlipped) {
            $behavior->flip = $flip;
        }

        return $transform;
    }

    protected function supportsEdgeEditing(Asset $asset): bool
    {
        $assetFs = $asset->getVolume()->getFs();

        if (!$assetFs instanceof AssetsFs || $assetFs->useLocalFs) {
            return false;
        }

        if ($asset->kind !== Asset::KIND_PDF) {
            if (!in_array(strtolower(pathinfo($asset->getFilename(), PATHINFO_EXTENSION)), ImageTransformer::SUPPORTED_IMAGE_FORMATS, true)) {
                return false;
            }

            $mimeType = $asset->getMimeType();
            $generalConfig = Craft::$app->getConfig()->getGeneral();

            if ($mimeType === 'image/gif' && !$generalConfig->transformGifs) {
                return false;
            }

            if ($mimeType === 'image/svg+xml' && !$generalConfig->transformSvgs) {
                return false;
            }
        }

        return true;
    }

    protected function focalPoint(Asset $asset, ?array $focalPoint, int $viewportRotation, float $imageRotation, array $cropData, array $imageDimensions, ?array $flipData, float $zoom): ?array
    {
        if (!$focalPoint) {
            return null;
        }

        if (!$asset->width || !$asset->height) {
            throw new NotSupportedException('Image dimensions are required to edit images.');
        }

        if (!$this->validDimensions($focalPoint['imageDimensions'] ?? [])) {
            throw new NotSupportedException('Valid image editor dimensions are required to edit images.');
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

        return [
            'x' => max(0, min(1, $focal['x'])),
            'y' => max(0, min(1, $focal['y'])),
        ];
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

        $crop = [
            'left' => (int)round(($editedDimensions['width'] / 2) + ($cropData['offsetX'] * $zoom * $adjustmentRatio) - ($width / 2)),
            'top' => (int)round(($editedDimensions['height'] / 2) + ($cropData['offsetY'] * $zoom * $adjustmentRatio) - ($height / 2)),
            'width' => $width,
            'height' => $height,
        ];

        return $this->constrainCrop($crop, $editedDimensions['width'], $editedDimensions['height']);
    }

    protected function sourceCrop(array $crop, int $sourceWidth, int $sourceHeight, int $rotation): array
    {
        return match ($rotation) {
            90 => [
                'left' => $crop['top'],
                'top' => $sourceHeight - $crop['left'] - $crop['width'],
                'width' => $crop['height'],
                'height' => $crop['width'],
            ],
            180 => [
                'left' => $sourceWidth - $crop['left'] - $crop['width'],
                'top' => $sourceHeight - $crop['top'] - $crop['height'],
                'width' => $crop['width'],
                'height' => $crop['height'],
            ],
            270 => [
                'left' => $sourceWidth - $crop['top'] - $crop['height'],
                'top' => $crop['left'],
                'width' => $crop['height'],
                'height' => $crop['width'],
            ],
            default => $crop,
        };
    }

    protected function constrainCrop(array $crop, int $width, int $height): array
    {
        $left = max(0, $crop['left']);
        $top = max(0, $crop['top']);
        $cropWidth = min($crop['width'], $width - $left);
        $cropHeight = min($crop['height'], $height - $top);

        if ($cropWidth <= 0 || $cropHeight <= 0) {
            throw new NotSupportedException('Valid image editor crop dimensions are required to edit images.');
        }

        return [
            'left' => $left,
            'top' => $top,
            'width' => $cropWidth,
            'height' => $cropHeight,
        ];
    }

    protected function ensureImageEditorData(array $cropData, array $imageDimensions, float $zoom): void
    {
        if (
            !isset($cropData['offsetX'], $cropData['offsetY']) ||
            !is_numeric($cropData['offsetX']) ||
            !is_numeric($cropData['offsetY']) ||
            !$this->validDimensions($cropData) ||
            !$this->validDimensions($imageDimensions) ||
            $zoom <= 0
        ) {
            throw new NotSupportedException('Valid image editor dimensions are required to edit images.');
        }
    }

    protected function rotation(int $viewportRotation, float $imageRotation): int
    {
        return ((int)round($imageRotation + $viewportRotation) % 360 + 360) % 360;
    }

    protected function hasRightAngleRotation(int $viewportRotation, float $imageRotation): bool
    {
        $rotation = fmod($imageRotation + $viewportRotation, 360);

        return in_array($rotation < 0 ? $rotation + 360 : $rotation, [0.0, 90.0, 180.0, 270.0], true);
    }

    protected function rotatedDimensions(int|float $width, int|float $height, int $rotation): array
    {
        return in_array($rotation, [90, 270], true)
            ? ['width' => (int)round($height), 'height' => (int)round($width)]
            : ['width' => (int)round($width), 'height' => (int)round($height)];
    }

    protected function validDimensions(array $dimensions): bool
    {
        return isset($dimensions['width'], $dimensions['height']) &&
            is_numeric($dimensions['width']) &&
            is_numeric($dimensions['height']) &&
            (float)$dimensions['width'] > 0 &&
            (float)$dimensions['height'] > 0;
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
                $asset->sanitizeOnUpload = $this->sanitizeEditedFile($asset);
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
        $newAsset->sanitizeOnUpload = $this->sanitizeEditedFile($asset);
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

    protected function sanitizeEditedFile(Asset $asset): bool
    {
        return $asset->getMimeType() === 'image/svg+xml';
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
                RequestOptions::CONNECT_TIMEOUT => 5,
                RequestOptions::TIMEOUT => 30,
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
