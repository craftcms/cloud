<?php

namespace craft\cloud\cli\controllers\assets;

use craft\cloud\cli\controllers\AssetsController;

class RepairController extends AssetsController
{
    public function actionDimensions(): int
    {
        return $this->actionRepairDimensions();
    }

    public function actionMetadata(): int
    {
        return $this->actionRepairMetadata();
    }

    public function actionSize(): int
    {
        return $this->actionRepairSize();
    }
}
