<?php

namespace craft\cloud\imagetransforms;

use Craft;
use craft\base\Component;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\cloud\fs\AssetsFs;
use craft\cloud\Module;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Html;
use craft\helpers\ImageTransforms as ImageTransformsHelper;
use craft\models\ImageTransform;
use League\Uri\Components\Query;
use League\Uri\Modifier;
use League\Uri\Uri;
use yii\base\NotSupportedException;

/**
 * TODO: ImageEditorTransformerInterface
 *
 * @internal
 */
class ImageTransformer extends Component implements ImageTransformerInterface
{
    // Source asset extensions Cloudflare Images can accept for transformations.
    public const SUPPORTED_IMAGE_FORMATS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'heic'];

    public static function fromImageEditor(
        Asset $asset,
        int $viewportRotation,
        float $imageRotation,
        array $cropData,
        array $imageDimensions,
        ?array $flipData,
        float $zoom,
    ): ?ImageTransform {
        $rotation = ((int)round($imageRotation + $viewportRotation) % 360 + 360) % 360;
        $flipX = !empty($flipData['x']);
        $flipY = !empty($flipData['y']);
        $flip = match (true) {
            $flipX && $flipY => 'hv',
            $flipX => 'h',
            $flipY => 'v',
            default => null,
        };

        self::ensureImageEditorData($cropData, $imageDimensions, $zoom);

        $sourceReferenceDimensions = self::rotatedDimensions($imageDimensions['width'], $imageDimensions['height'], 0);
        $cropReferenceDimensions = self::rotatedDimensions($imageDimensions['width'], $imageDimensions['height'], $rotation);
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

        if (!in_array($rotation, [0, 90, 180, 270], true)) {
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
            $crop = self::crop($asset, $rotation, $cropData, $imageDimensions, $zoom);

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

    private static function ensureImageEditorData(array $cropData, array $imageDimensions, float $zoom): void
    {
        if (
            !isset($cropData['offsetX'], $cropData['offsetY']) ||
            !is_numeric($cropData['offsetX']) ||
            !is_numeric($cropData['offsetY']) ||
            !self::validDimensions($cropData) ||
            !self::validDimensions($imageDimensions) ||
            $zoom <= 0
        ) {
            throw new NotSupportedException('Valid image editor dimensions are required to edit images.');
        }
    }

    private static function validDimensions(array $dimensions): bool
    {
        return isset($dimensions['width'], $dimensions['height']) &&
            is_numeric($dimensions['width']) &&
            is_numeric($dimensions['height']) &&
            (float)$dimensions['width'] > 0 &&
            (float)$dimensions['height'] > 0;
    }

    private static function crop(Asset $asset, int $rotation, array $cropData, array $imageDimensions, float $zoom): array
    {
        $adjustmentRatio = min(
            $asset->width / $imageDimensions['width'],
            $asset->height / $imageDimensions['height'],
        );
        $editedDimensions = self::rotatedDimensions($asset->width, $asset->height, $rotation);

        $width = (int)round($cropData['width'] * $zoom * $adjustmentRatio);
        $height = (int)round($cropData['height'] * $zoom * $adjustmentRatio);
        $crop = [
            'left' => (int)round(($editedDimensions['width'] / 2) + ($cropData['offsetX'] * $zoom * $adjustmentRatio) - ($width / 2)),
            'top' => (int)round(($editedDimensions['height'] / 2) + ($cropData['offsetY'] * $zoom * $adjustmentRatio) - ($height / 2)),
            'width' => $width,
            'height' => $height,
        ];

        return self::constrainCrop(
            self::sourceCrop($crop, $asset->width, $asset->height, $rotation),
            $asset->width,
            $asset->height,
        );
    }

    private static function sourceCrop(array $crop, int $sourceWidth, int $sourceHeight, int $rotation): array
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

    private static function constrainCrop(array $crop, int $sourceWidth, int $sourceHeight): array
    {
        $left = max(0, $crop['left']);
        $top = max(0, $crop['top']);

        return [
            'left' => $left,
            'top' => $top,
            'width' => min($crop['width'], $sourceWidth - $left),
            'height' => min($crop['height'], $sourceHeight - $top),
        ];
    }

    private static function rotatedDimensions(int|float $width, int|float $height, int $rotation): array
    {
        return in_array($rotation, [90, 270], true)
            ? ['width' => (int)round($height), 'height' => (int)round($width)]
            : ['width' => (int)round($width), 'height' => (int)round($height)];
    }

    public function getTransformUrl(Asset $asset, mixed $transform, bool $immediately): string
    {
        $imageTransform = $this->normalizeTransform($transform);

        if (!$imageTransform) {
            throw new NotSupportedException('Invalid image transform.');
        }

        $assetFs = $asset->getVolume()->getFs();

        if (!$assetFs instanceof AssetsFs || $assetFs->useLocalFs) {
            throw new NotSupportedException('Cloud transforms are only supported for Cloud assets.');
        }

        $behavior = $imageTransform->getBehavior('cloud');

        if (!$behavior instanceof ImageTransformBehavior) {
            throw new \RuntimeException('Cloud image transform behavior is not attached.');
        }

        if ($asset->kind !== Asset::KIND_PDF) {
            $mimeType = $asset->getMimeType();

            if ($mimeType === 'image/gif' && !Craft::$app->getConfig()->getGeneral()->transformGifs) {
                throw new NotSupportedException('GIF files shouldn’t be transformed.');
            }

            if ($mimeType === 'image/svg+xml' && !Craft::$app->getConfig()->getGeneral()->transformSvgs) {
                throw new NotSupportedException('SVG files shouldn’t be transformed.');
            }
        }

        $options = $behavior->toOptions($this->applyAssetFocalPointGravity($asset, $imageTransform));

        if ($asset->kind === Asset::KIND_PDF) {
            $options['format'] ??= 'auto';
        }

        $uri = Modifier::wrap($this->createBaseUri($asset))
            ->mergeQuery(Query::fromVariable($options))
            ->removeQueryParameters('v')
            ->removeEmptyQueryPairs()
            ->unwrap();

        return Module::getInstance()->getUrlSigner()->sign((string) $uri);
    }

    public function invalidateAssetTransforms(Asset $asset): void
    {
    }

    protected function applyAssetFocalPointGravity(Asset $asset, ImageTransform $imageTransform): array|string|null
    {
        // @phpstan-ignore-next-line property.notFound
        if (!$asset->getHasFocalPoint() || isset($imageTransform->gravity)) {
            return null;
        }

        return $asset->getFocalPoint();
    }

    private function normalizeTransform(mixed $transform): ?ImageTransform
    {
        $cloudOptions = [];

        if (is_array($transform)) {
            foreach (['width', 'height'] as $attribute) {
                if (isset($transform[$attribute])) {
                    $transform[$attribute] = round((float)$transform[$attribute]);
                }
            }

            foreach ($this->cloudProperties() as $property) {
                $name = $property->getName();

                if (!array_key_exists($name, $transform)) {
                    continue;
                }

                [$valid, $value] = $this->normalizeCloudOption($property, $transform[$name]);

                if ($valid) {
                    $cloudOptions[$name] = $value;
                }

                unset($transform[$name]);
            }
        }

        $imageTransform = ImageTransformsHelper::normalizeTransform($transform)
            ?? ($cloudOptions ? Craft::createObject(ImageTransform::class) : null);

        if ($imageTransform && $cloudOptions) {
            $imageTransform = clone $imageTransform;
            $behavior = $imageTransform->getBehavior('cloud');

            if ($behavior instanceof ImageTransformBehavior) {
                Craft::configure($behavior, $cloudOptions);
            }
        }

        return $imageTransform;
    }

    private function cloudProperties(): array
    {
        static $properties = null;

        return $properties ??= array_filter(
            (new \ReflectionClass(ImageTransformBehavior::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
            fn(\ReflectionProperty $property) => $property->getDeclaringClass()->getName() === ImageTransformBehavior::class,
        );
    }

    private function normalizeCloudOption(\ReflectionProperty $property, mixed $value): array
    {
        if ($value === null) {
            return [true, null];
        }

        $type = $property->getType();
        $types = $type instanceof \ReflectionUnionType
            ? array_map(fn(\ReflectionNamedType $type) => $type->getName(), $type->getTypes())
            : ($type instanceof \ReflectionNamedType ? [$type->getName()] : []);

        if (in_array('int', $types, true)) {
            $intValue = is_int($value) || is_string($value)
                ? filter_var($value, FILTER_VALIDATE_INT)
                : false;

            if ($intValue !== false) {
                return [$property->getName() !== 'page' || $intValue >= 1, $intValue];
            }

            if (!in_array('string', $types, true)) {
                return [false, $intValue];
            }
        }

        if (in_array('float', $types, true)) {
            $value = is_int($value) || is_float($value) || is_string($value)
                ? filter_var($value, FILTER_VALIDATE_FLOAT)
                : false;

            return [$value !== false, $value];
        }

        if (in_array('bool', $types, true)) {
            $value = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            return [$value !== null, $value];
        }

        if (in_array('string', $types, true) || in_array('array', $types, true)) {
            return [
                (in_array('string', $types, true) && is_string($value)) ||
                (in_array('array', $types, true) && is_array($value)),
                $value,
            ];
        }

        return [true, $value];
    }

    private function createBaseUri(Asset $asset): Uri
    {
        if (version_compare(Craft::$app->version, '5.0', '>=')) {
            // @phpstan-ignore argument.type, arguments.count (Craft 5 compatibility)
            $url = Assets::generateUrl($asset);
        } else {
            $fs = $asset->getVolume()->getFs();

            // @phpstan-ignore argument.type, arguments.count (Craft 4 compatibility)
            $url = Assets::generateUrl($fs, $asset);
        }

        return Uri::new(Html::encodeSpaces($url));
    }
}
