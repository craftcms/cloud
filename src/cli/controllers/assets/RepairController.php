<?php

namespace craft\cloud\cli\controllers\assets;

use craft\cloud\cli\controllers\AssetsController;

class RepairController extends AssetsController
{
    public $defaultAction = 'missing';

    public function actionMissing(): int
    {
        return $this->repairMissingAssetData();
    }

    public function actionMetadata(): int
    {
        return $this->actionRepairMetadata();
    }
}
