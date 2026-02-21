<?php

namespace craft\cloud;

use Craft;
use craft\base\Component;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Html;
use craft\models\ImageTransform;
use League\Uri\Components\Query;
use League\Uri\Modifier;
use League\Uri\Uri;
use Psr\Http\Message\UriInterface;
use yii\base\NotSupportedException;

/**
 * TODO: ImageEditorTransformerInterface
 */
class ImageTransformer extends Component implements ImageTransformerInterface
{
    public const SUPPORTED_IMAGE_FORMATS = ['jpg', 'jpeg', 'gif', 'png', 'avif', 'webp'];
    private const SIGNING_PARAM = 's';

    public function getTransformUrl(Asset $asset, ImageTransform $imageTransform, bool $immediately): string
    {
        $fs = $asset->getVolume()->getTransformFs();
        $assetUrl = Html::encodeSpaces(Assets::generateUrl($fs, $asset));
        $mimeType = $asset->getMimeType();

        if ($mimeType === 'image/gif' && !Craft::$app->getConfig()->getGeneral()->transformGifs) {
            throw new NotSupportedException('GIF files shouldn’t be transformed.');
        }

        if ($mimeType === 'image/svg+xml' && !Craft::$app->getConfig()->getGeneral()->transformSvgs) {
            throw new NotSupportedException('SVG files shouldn’t be transformed.');
        }

        $cfTransform = CloudflareImagesTransform::fromAsset($asset, $imageTransform);
        $uri = Modifier::wrap(Uri::new($assetUrl))
            ->mergeQuery(Query::fromVariable($cfTransform)->value())
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
            Plugin::getInstance()->getConfig()->signingKey,
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
