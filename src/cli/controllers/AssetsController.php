<?php

namespace craft\cloud\cli\controllers;

use Craft;
use craft\cloud\fs\Fs;
use craft\console\Controller;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use yii\console\ExitCode;
use yii\helpers\Console;

class AssetsController extends Controller
{
    /**
     * @var array<string>|null
     */
    public ?array $volume = null;

    /**
     * @var array<int>|null
     */
    public ?array $assetId = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'replace-metadata',
            'repair-dimensions',
            'repair-metadata',
            'dimensions',
            'metadata' => ['volume', 'assetId'],
            default => []
        });
    }

    public function actionRepairDimensions(): int
    {
        $assets = Asset::find()
            ->volume($this->volume)
            ->id($this->assetId)
            ->kind(Asset::KIND_IMAGE)
            ->andWhere(['or', ['assets.width' => null], ['assets.height' => null]])
            ->collect();

        $repaired = 0;
        $skipped = 0;

        $assets->each(function(Asset $asset) use (&$repaired, &$skipped) {
            $dimensions = $this->repairAssetDimensions($asset);

            if ($dimensions === null) {
                $skipped++;
                $this->stdout("Skipped `$asset->path`: dimensions could not be determined." . PHP_EOL, Console::FG_YELLOW);
                return;
            }

            $repaired++;
            $this->stdout("Repaired `$asset->path`: {$dimensions[0]}x{$dimensions[1]}." . PHP_EOL, Console::FG_GREEN);
        });

        $this->stdout("Repaired {$repaired} asset" . ($repaired === 1 ? '' : 's') . '.' . PHP_EOL, Console::FG_GREEN);

        if ($skipped > 0) {
            $this->stdout("Skipped {$skipped} asset" . ($skipped === 1 ? '' : 's') . '.' . PHP_EOL, Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    public function actionRepairMetadata(): int
    {
        $assets = Asset::find()
            ->volume($this->volume)
            ->id($this->assetId)
            ->collect();

        $assets->each(function(Asset $asset) {
            $this->do(
                "Repairing metadata for `$asset->path`",
                fn() => $this->repairAssetMetadata($asset),
            );
        });

        return ExitCode::OK;
    }

    public function actionReplaceMetadata(): int
    {
        $this->stdout(
            'Deprecated: use `cloud/assets/repair/metadata` instead.' . PHP_EOL,
            Console::FG_YELLOW,
        );

        return $this->actionRepairMetadata();
    }

    /**
     * @return array{int,int}|null
     */
    protected function repairAssetDimensions(Asset $asset): ?array
    {
        $fs = $asset->getVolume()->getFs();

        if (!$fs instanceof Fs) {
            return null;
        }

        $dimensions = $fs->getImageDimensions($asset->getPath());

        if ($dimensions === null || !$dimensions[0] || !$dimensions[1]) {
            return null;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::ASSETS, [
                'width' => $dimensions[0],
                'height' => $dimensions[1],
            ], ['id' => $asset->id])
            ->execute();

        $asset->setWidth($dimensions[0]);
        $asset->setHeight($dimensions[1]);

        return $dimensions;
    }

    protected function repairAssetMetadata(Asset $asset): void
    {
        $fs = $asset->getVolume()->getFs();

        if (!$fs instanceof Fs) {
            return;
        }

        $path = $asset->getPath();

        $config = [
            'ContentType' => FileHelper::getMimeType($path),
            'MetadataDirective' => 'REPLACE',
        ];

        $fs->replaceMetadata($path, $config);
    }
}
