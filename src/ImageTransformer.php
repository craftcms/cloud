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
use Illuminate\Support\Collection;
use yii\base\NotSupportedException;

/**
 * TODO: ImageEditorTransformerInterface
 */
class ImageTransformer extends Component implements ImageTransformerInterface
{
    public const SUPPORTED_IMAGE_FORMATS = ['jpg', 'jpeg', 'gif', 'png', 'avif', 'webp'];
    private const SIGNING_PARAM = 's';
    private Asset $asset;

    public function init(): void
    {
        parent::init();
    }

    public function getTransformUrl(Asset $asset, ImageTransform $imageTransform, bool $immediately): string
    {
        $this->asset = $asset;
        $fs = $asset->getVolume()->getTransformFs();
        $assetUrl = Html::encodeSpaces(Assets::generateUrl($fs, $this->asset));
        $mimeType = $asset->getMimeType();

        if ($mimeType === 'image/gif' && !Craft::$app->getConfig()->getGeneral()->transformGifs) {
            throw new NotSupportedException('GIF files shouldn’t be transformed.');
        }

        if ($mimeType === 'image/svg+xml' && !Craft::$app->getConfig()->getGeneral()->transformSvgs) {
            throw new NotSupportedException('SVG files shouldn’t be transformed.');
        }

        $transformParams = $this->buildTransformParams($imageTransform);
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

    private function buildTransformParams(ImageTransform $imageTransform): array
    {
        return Collection::make([
            'width' => $imageTransform->width,
            'height' => $imageTransform->height,
            'quality' => $imageTransform->quality,
            'format' => $this->getFormatValue($imageTransform),
            'fit' => $this->getFitValue($imageTransform),
            'background' => $this->getBackgroundValue($imageTransform),
            'gravity' => $this->getGravityValue($imageTransform),
        ])->whereNotNull()->all();
    }

    private function getGravityValue(ImageTransform $imageTransform): ?array
    {
        if ($this->asset->getHasFocalPoint()) {
            return $this->asset->getFocalPoint();
        }

        if ($imageTransform->position === 'center-center') {
            return null;
        }

        // TODO: maybe just do this in Craft
        $parts = explode('-', $imageTransform->position);

        try {
            $x = match ($parts[1] ?? null) {
                'left' => 0,
                'center' => 0.5,
                'right' => 1,
            };
            $y = match ($parts[0] ?? null) {
                'top' => 0,
                'center' => 0.5,
                'bottom' => 1,
            };
        } catch (\UnhandledMatchError $e) {
            Craft::warning("Invalid position value: `{$imageTransform->position}`", __METHOD__);
            return null;
        }

        return [
            'x' => $x,
            'y' => $y,
        ];
    }

    private function getBackgroundValue(ImageTransform $imageTransform): ?string
    {
        return $imageTransform->mode === 'letterbox'
            ? $imageTransform->fill ?? '#FFFFFF'
            : null;
    }

    private function getFitValue(ImageTransform $imageTransform): string
    {
        // @see https://developers.cloudflare.com/images/transform-images/transform-via-url/#fit
        // Cloudflare doesn't have an exact match to `stretch`.
        // `cover` is close, but will crop instead of stretching.
        return match ($imageTransform->mode) {
            'fit' => $imageTransform->upscale ? 'contain' : 'scale-down',
            'stretch' => 'cover',
            'letterbox' => 'pad',
            default => $imageTransform->upscale ? 'cover' : 'crop',
        };
    }

    private function getFormatValue(ImageTransform $imageTransform): ?string
    {
        if ($imageTransform->format === 'jpg' && $imageTransform->interlace === 'none') {
            return 'baseline-jpeg';
        }

        return match ($imageTransform->format) {
            'jpg' => 'jpeg',
            default => $imageTransform->format,
        };
    }

    private function sign(string $path, $params): string
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
