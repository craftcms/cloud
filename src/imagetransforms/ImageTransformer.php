<?php

namespace craft\cloud\imagetransforms;

use Craft;
use craft\base\Component;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\cloud\Module;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Html;
use League\Uri\Components\Query;
use League\Uri\Contracts\UriInterface;
use League\Uri\Modifier;
use League\Uri\Uri;
use yii\base\NotSupportedException;

/**
 * TODO: ImageEditorTransformerInterface
 */
class ImageTransformer extends Component implements ImageTransformerInterface
{
    public const SUPPORTED_IMAGE_FORMATS = ['jpg', 'jpeg', 'gif', 'png', 'avif', 'webp'];
    private const SIGNING_PARAM = 's';

    public function getTransformUrl(Asset $asset, \craft\models\ImageTransform $imageTransform, bool $immediately): string
    {
        if (version_compare(Craft::$app->version, '5.0', '>=')) {
            $assetUrl = Html::encodeSpaces(Assets::generateUrl($asset));
        } else {
            $fs = $asset->getVolume()->getTransformFs();
            /** @phpstan-ignore argument.type (Craft 4 compatibility) */
            $assetUrl = Html::encodeSpaces(Assets::generateUrl($fs, $asset));
        }

        $mimeType = $asset->getMimeType();

        if ($mimeType === 'image/gif' && !Craft::$app->getConfig()->getGeneral()->transformGifs) {
            throw new NotSupportedException('GIF files shouldn’t be transformed.');
        }

        if ($mimeType === 'image/svg+xml' && !Craft::$app->getConfig()->getGeneral()->transformSvgs) {
            throw new NotSupportedException('SVG files shouldn’t be transformed.');
        }

        // ImageTransform DI will not work on Craft 4, so we convert the object.
        // @see https://github.com/craftcms/cms/pull/15646
        if (!$imageTransform instanceof ImageTransform) {
            $imageTransform = Craft::createObject(ImageTransform::class, [$imageTransform->toArray()]);
        }

        if ($asset->getHasFocalPoint() && !isset($imageTransform->gravity)) {
            $imageTransform->gravity = $asset->getFocalPoint();
        }

        $query = Query::fromVariable($imageTransform->toOptions());
        $uri = Modifier::wrap(Uri::new($assetUrl))
            ->mergeQuery($query)
            ->unwrap();

        return (string) $this->sign($uri);
    }

    public function invalidateAssetTransforms(Asset $asset): void
    {
    }

    private function sign(UriInterface $uri): UriInterface
    {
        $data = "{$uri->getPath()}#?{$uri->getQuery()}";

        Craft::info("Signing transform: `{$data}`", __METHOD__);

        // https://developers.cloudflare.com/workers/examples/signing-requests
        $hash = hash_hmac(
            'sha256',
            $data,
            Module::getInstance()->getConfig()->signingKey,
            true,
        );

        $signature = $this->base64UrlEncode($hash);

        return Modifier::wrap($uri)
            ->mergeQueryParameters([self::SIGNING_PARAM => $signature])
            ->unwrap();
    }

    private function base64UrlEncode(string $data): string
    {
        $base64Url = strtr(base64_encode($data), '+/', '-_');

        return rtrim($base64Url, '=');
    }
}
