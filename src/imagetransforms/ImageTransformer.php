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
