<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\cloud\twig;

use craft\cloud\Helper;
use craft\cloud\Module;
use craft\elements\Asset;
use craft\helpers\Html;
use craft\helpers\ImageTransforms;
use craft\helpers\Template;
use Twig\Markup;
use yii\di\ServiceLocator;

class CloudVariable extends ServiceLocator
{
    public function artifactUrl(string $path): string
    {
        return Helper::artifactUrl($path);
    }

    public function isCraftCloud(): bool
    {
        return Helper::isCraftCloud();
    }

    public function getImg(Asset $asset, mixed $transform = null, ?array $sizes = null): ?Markup
    {
        if ($asset->kind !== Asset::KIND_PDF) {
            return $asset->getImg($transform, $sizes);
        }

        if (!$transform) {
            return null;
        }

        $url = $asset->getUrl($transform);

        if (!$url || !Module::getInstance()->getUrlSigner()->verify($url)) {
            return null;
        }

        $imageTransform = ImageTransforms::normalizeTransform($transform);
        $attributes = [
            'src' => $url,
            'alt' => $asset->alt ?? $asset->getFilename(),
        ];

        if ($imageTransform?->width !== null) {
            $attributes['width'] = $imageTransform->width;
        }

        if ($imageTransform?->height !== null) {
            $attributes['height'] = $imageTransform->height;
        }

        return Template::raw(Html::tag('img', '', $attributes));
    }

    public function enableEsi(): void
    {
        Module::getInstance()->getEsi()->prepareResponse();
    }

    public function esi(string $template, $variables = []): Markup
    {
        return Template::raw(
            Module::getInstance()->getEsi()->render($template, $variables)
        );
    }
}
