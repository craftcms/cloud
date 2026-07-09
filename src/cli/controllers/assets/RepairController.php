<?php

namespace craft\cloud\cli\controllers\assets;

use craft\cloud\cli\controllers\AssetsController;

class RepairController extends AssetsController
{
    public function createAction($id)
    {
        if (!in_array($id, ['index', 'metadata'], true)) {
            return null;
        }

        return parent::createAction($id);
    }

    public function actionIndex(): int
    {
        return $this->repairMissingAssetIndex();
    }

    public function actionMetadata(): int
    {
        return $this->actionRepairMetadata();
    }
}
