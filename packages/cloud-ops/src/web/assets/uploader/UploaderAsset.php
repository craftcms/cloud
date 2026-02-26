<?php

namespace craft\cloud\ops\web\assets\uploader;

use Craft;
use craft\cloud\Plugin;
use craft\helpers\ConfigHelper;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class UploaderAsset extends AssetBundle
{
    /** @inheritdoc */
    public $sourcePath = __DIR__ . '/dist';

    /** @inheritdoc */
    public $js = [
        'Uploader.js',
    ];

    /**
     * @inheritdoc
     */
    public $depends = [
        CpAsset::class,
    ];

    public function registerAssetFiles($view): void
    {
        if (!Plugin::getInstance()->getConfig()->useAssetCdn) {
            return;
        }

        parent::registerAssetFiles($view);

        $maxFileSize = ConfigHelper::sizeInBytes(Craft::$app->getConfig()->getGeneral()->maxUploadFileSize);
        $js = <<<JS
window.Craft.CloudUploader.defaults.maxFileSize = $maxFileSize;
JS;
        $view->registerJs($js, \yii\web\View::POS_END);
    }
}
