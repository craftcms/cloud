<?php

namespace craft\cloud\web\assets\assetthumbfallback;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

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
}
