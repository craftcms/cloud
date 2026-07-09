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
        $page = null;

        if (is_array($transform)) {
            foreach (['width', 'height'] as $attribute) {
                if (isset($transform[$attribute])) {
                    $transform[$attribute] = round((float)$transform[$attribute]);
                }
            }

            if (array_key_exists('page', $transform)) {
                if (is_numeric($transform['page'])) {
                    $transform['page'] = (int)$transform['page'];
                } else {
                    unset($transform['page']);
                }
            }

            if (array_key_exists('transform', $transform) && array_key_exists('page', $transform)) {
                $page = $transform['page'];
            }
        }

        $imageTransform = ImageTransformsHelper::normalizeTransform($transform);

        if ($imageTransform && $page !== null) {
            $behavior = $imageTransform->getBehavior('cloud');

            if ($behavior instanceof ImageTransformBehavior) {
                $behavior->page = $page;
            }
        }

        return $imageTransform;
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
