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

    public function getTransformUrl(Asset $asset, ImageTransform $imageTransform, bool $immediately): string
    {
        $mimeType = $asset->getMimeType();

        if ($mimeType === 'image/gif' && !Craft::$app->getConfig()->getGeneral()->transformGifs) {
            throw new NotSupportedException('GIF files shouldn’t be transformed.');
        }

        if ($mimeType === 'image/svg+xml' && !Craft::$app->getConfig()->getGeneral()->transformSvgs) {
            throw new NotSupportedException('SVG files shouldn’t be transformed.');
        }

        $gravity = $this->applyAssetFocalPointGravity($asset, $imageTransform);

        $query = Query::fromVariable($this->getTransformOptions($imageTransform, $gravity));
        $uri = Modifier::wrap($this->getAssetUri($asset))
            ->mergeQuery($query)
            ->unwrap();

        return Module::getInstance()->getUrlSigner()->sign((string) $uri);
    }

    public function getPdfTransformUrl(Asset $asset, mixed $transform): ?string
    {
        if ($asset->kind !== Asset::KIND_PDF) {
            return null;
        }

        $assetFs = $this->getAssetCdnFs($asset);

        if (!$assetFs) {
            return null;
        }

        $imageTransform = $this->normalizeTransform($transform);

        if (!$imageTransform) {
            return null;
        }

        $options = $this->getTransformOptions($imageTransform);
        $options['format'] ??= 'auto';

        $query = Query::fromVariable($options);
        $uri = Modifier::wrap($this->getAssetUri($asset, $assetFs))
            ->mergeQuery($query)
            ->unwrap();

        return Module::getInstance()->getUrlSigner()->sign((string) $uri);
    }

    public function getPdfThumbUrl(Asset $asset, int $width, int $height): ?string
    {
        return $this->getPdfTransformUrl($asset, new ImageTransform([
            'width' => $width,
            'height' => $height,
            'mode' => 'crop',
        ]));
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

    private function getTransformOptions(ImageTransform $imageTransform, array|string|null $gravity = null): array
    {
        $behavior = $imageTransform->getBehavior('cloud');

        if (!$behavior instanceof ImageTransformBehavior) {
            throw new \RuntimeException('Cloud image transform behavior is not attached.');
        }

        return $behavior->toOptions($gravity);
    }

    private function normalizeTransform(mixed $transform): ?ImageTransform
    {
        if (is_array($transform)) {
            foreach (['width', 'height'] as $attribute) {
                if (isset($transform[$attribute])) {
                    $transform[$attribute] = round((float)$transform[$attribute]);
                }
            }
        }

        return ImageTransformsHelper::normalizeTransform($transform);
    }

    private function getAssetUri(Asset $asset, ?AssetsFs $assetFs = null): Uri
    {
        $assetFs ??= $this->getAssetCdnFs($asset);

        if (version_compare(Craft::$app->version, '5.0', '>=')) {
            // @phpstan-ignore argument.type, arguments.count (Craft 5 compatibility)
            $url = Assets::generateUrl($asset);
        } else {
            $transformFs = $asset->getVolume()->getTransformFs();

            // @phpstan-ignore argument.type, arguments.count (Craft 4 compatibility)
            $url = Assets::generateUrl($transformFs, $asset);
        }

        $uri = Uri::new(Html::encodeSpaces($url));

        if (!$assetFs) {
            return $uri;
        }

        // Craft's asset revision query isn't needed for Cloud transforms,
        // regardless of the revAssetUrls config setting.
        return Uri::new((string) Modifier::wrap($uri)
            ->removeQueryParameters('v')
            ->removeEmptyQueryPairs()
            ->unwrap());
    }

    private function getAssetCdnFs(Asset $asset): ?AssetsFs
    {
        $assetFs = $asset->getVolume()->getFs();

        if (!$assetFs instanceof AssetsFs || $assetFs->useLocalFs) {
            return null;
        }

        return $assetFs;
    }
}
