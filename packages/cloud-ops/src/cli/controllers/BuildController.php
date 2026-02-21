<?php

namespace craft\cloud\ops\cli\controllers;

use Composer\Semver\Semver;
use Craft;
use craft\console\Controller;
use craft\helpers\App;
use yii\console\Exception;

class BuildController extends Controller
{
    use RunningTimeTrait;

    public $defaultAction = 'build';
    public ?string $publishAssetBundlesTo = null;
    public string $craftEdition = '';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'publishAssetBundlesTo',
            'craftEdition',
        ]);
    }

    public function actionBuild(): int
    {
        $this->validateEdition($this->craftEdition);

        return $this->run('/cloud-ops/asset-bundles/publish', [
            'to' => $this->publishAssetBundlesTo,
        ]);
    }

    private function validateEdition(string $edition): void
    {
        $craftVersion = Craft::$app->getVersion();
        $editionFromEnv = App::env('CRAFT_EDITION');

        // CRAFT_EDITION is enforced in these versions, so we don't need to validate
        if ($editionFromEnv && Semver::satisfies($craftVersion, '^4.10 || ^5.2')) {
            return;
        }

        $editionFromProjectConfig = Craft::$app->getProjectConfig()->get('system.edition', true);

        if (!$editionFromProjectConfig || !$edition) {
            throw new Exception('Unable to determine the Craft CMS edition.');
        }

        if ($edition !== $editionFromProjectConfig) {
            throw new Exception("This Craft Cloud project is only valid for the Craft CMS edition “{$edition}”.");
        }
    }
}
