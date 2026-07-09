<?php

namespace craft\cloud\web\assets\assetthumbfallback;

use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Json;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use yii\web\View;

class AssetThumbFallbackAsset extends AssetBundle
{
    /** @inheritdoc */
    public $sourcePath = __DIR__ . '/dist';

    /** @inheritdoc */
    public $js = [
        'AssetThumbFallback.js',
    ];

    /** @inheritdoc */
    public $depends = [
        CpAsset::class,
    ];

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        $settings = Json::encode([
            'iconUrlsByExtension' => $this->iconUrlsByExtension(),
        ]);

        $view->registerJs(<<<JS
window.Craft = window.Craft || {};
window.Craft.CloudAssetThumbFallback = {$settings};
JS, View::POS_HEAD);
    }

    private function iconUrlsByExtension(): array
    {
        $iconUrlsByExtension = [];

        foreach (AssetsHelper::getFileKinds() as $fileKind) {
            foreach ($fileKind['extensions'] ?? [] as $extension) {
                $extension = strtolower($extension);
                $iconUrlsByExtension[$extension] = AssetsHelper::iconUrl($extension);
            }
        }

        return $iconUrlsByExtension;
    }
}
