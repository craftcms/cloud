<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\cloud\twig;

use craft\cloud\Helper;
use craft\cloud\Plugin;
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

    public function enableEsi(): void
    {
        Plugin::getInstance()->getEsi()->prepareResponse();
    }

    public function esi(string $template, $variables = []): Markup
    {
        return Template::raw(
            Plugin::getInstance()->getEsi()->render($template, $variables)
        );
    }
}
