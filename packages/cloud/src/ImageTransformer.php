<?php

namespace craft\cloud;

use Craft;
use craft\base\Component;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\ImageTransform;
use yii\base\NotSupportedException;

/**
 * TODO: ImageEditorTransformerInterface
 */
class ImageTransformer extends Component implements ImageTransformerInterface
{
    public const SUPPORTED_IMAGE_FORMATS = ['jpg', 'jpeg', 'gif', 'png', 'avif', 'webp'];
    private const SIGNING_PARAM = 's';

    public function init(): void
    {
        parent::init();
    }

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

        $transformParams = array_filter(get_object_vars($cfTransform), fn($v) => $v !== null);

        $path = parse_url($assetUrl, PHP_URL_PATH);
        $params = $transformParams + [
            self::SIGNING_PARAM => $this->sign($path, $transformParams),
        ];

        $query = http_build_query($params);

        return UrlHelper::url($assetUrl . ($query ? "?{$query}" : ''));
    }

    public function invalidateAssetTransforms(Asset $asset): void
    {
    }

    private function sign(string $path, array $params): string
    {
        $paramString = http_build_query($params);
        $data = "$path#?$paramString";

        Craft::info("Signing transform: `{$data}`", __METHOD__);

        // https://developers.cloudflare.com/workers/examples/signing-requests
        $hash = hash_hmac(
            'sha256',
            $data,
            Plugin::getInstance()->getConfig()->signingKey,
            true,
        );

        return $this->base64UrlEncode($hash);
    }

    private function base64UrlEncode(string $data): string
    {
        $base64Url = strtr(base64_encode($data), '+/', '-_');

        return rtrim($base64Url, '=');
    }
}
