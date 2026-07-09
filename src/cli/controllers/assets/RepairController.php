<?php

namespace craft\cloud\cli\controllers\assets;

use craft\cloud\cli\controllers\AssetsController;

class RepairController extends AssetsController
{
    public $defaultAction = 'missing';

    public function createAction($id)
    {
        if (!in_array($id, ['missing', 'metadata'], true)) {
            return null;
        }

        return parent::createAction($id);
    }

    public function actionMissing(): int
    {
        return $this->repairMissingAssetData();
    }

    public function actionMetadata(): int
    {
        return $this->repairAssetObjectMetadataForAssets();
    }
}
