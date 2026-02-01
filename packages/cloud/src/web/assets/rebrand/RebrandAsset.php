<?php

namespace craft\cloud\web\assets\rebrand;

use Craft;

class RebrandAsset extends \craft\web\AssetBundle
{
    public function init()
    {
        $this->sourcePath = Craft::$app->getPath()->getRebrandPath();
        parent::init();
    }
}
