<?php

namespace craft\cloud\cli\controllers\assets;

use craft\console\Controller;

class RepairController extends Controller
{
    use AssetRepairTrait;

    public $defaultAction = 'missing';

    public function actionMissing(): int
    {
        return $this->repairMissingAssetData();
    }

    public function actionMetadata(): int
    {
        return $this->repairAssetObjectMetadataForAssets();
    }
}
