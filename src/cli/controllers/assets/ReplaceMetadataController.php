<?php

namespace craft\cloud\cli\controllers\assets;

use craft\console\Controller;
use yii\helpers\Console;

class ReplaceMetadataController extends Controller
{
    use AssetRepairTrait;

    public function actionIndex(): int
    {
        $this->stdout(
            'Deprecated: use `cloud/assets/repair/metadata` to repair object metadata instead.' . PHP_EOL,
            Console::FG_YELLOW,
        );

        return $this->repairAssetObjectMetadataForAssets();
    }
}
