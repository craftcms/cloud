<?php

namespace craft\cloud\cli\controllers;

use Craft;
use craft\cloud\fs\Fs;
use craft\console\Controller;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use Throwable;
use yii\base\Exception;
use yii\console\ExitCode;
use yii\helpers\Console;

class AssetsController extends Controller
{
    private const CRAFT_STREAM_DIMENSION_FIX_VERSION = [
        '4' => '4.18.4',
        '5' => '5.10.9',
    ];

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
            'repair-metadata',
            'index',
            'metadata' => ['volume', 'assetId'],
            default => []
        });
    }

    protected function repairMissingAssetIndex(): int
    {
        $repaired = 0;
        $skipped = 0;
        $warnedAboutDimensions = false;

        $assets = Asset::find()
            ->volume($this->volume)
            ->id($this->assetId)
            ->andWhere(['or',
                ['assets.size' => null],
                ['and',
                    ['assets.kind' => Asset::KIND_IMAGE],
                    ['or', ['assets.width' => null], ['assets.height' => null]],
                ],
            ]);

        foreach ($assets->each() as $asset) {
            /** @var Asset $asset */
            $path = $asset->getPath();
            $changes = [];

            if ($asset->size === null) {
                $size = $this->repairAssetSize($asset);

                if ($size === null) {
                    $this->stdout("Skipped `{$path}` size: could not be determined." . PHP_EOL, Console::FG_YELLOW);
                } else {
                    $changes[] = "{$size} bytes";
                }
            }

            if ($asset->kind === Asset::KIND_IMAGE && ($asset->getWidth() === null || $asset->getHeight() === null)) {
                if (!$warnedAboutDimensions) {
                    $this->warnIfCraftStreamDimensionFixMissing();
                    $warnedAboutDimensions = true;
                }

                $dimensions = $this->repairAssetDimensions($asset);

                if ($dimensions === null) {
                    if (!$asset->getVolume()->getFs() instanceof Fs) {
                        $this->stdout("Skipped `{$path}` dimensions: volume filesystem is not supported." . PHP_EOL, Console::FG_YELLOW);
                        continue;
                    }

                    $this->stdout("Skipped `{$path}` dimensions: could not be determined." . PHP_EOL, Console::FG_YELLOW);
                } else {
                    $changes[] = "{$dimensions[0]}x{$dimensions[1]}";
                }
            }

            if ($changes === []) {
                $skipped++;
                continue;
            }

            $repaired++;
            $this->stdout("Repaired `{$path}`: " . implode(', ', $changes) . '.' . PHP_EOL, Console::FG_GREEN);
        }

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
            ->id($this->assetId);

        foreach ($assets->each() as $asset) {
            /** @var Asset $asset */
            $this->do(
                "Repairing object metadata for `{$asset->getPath()}`",
                fn() => $this->repairAssetObjectMetadata($asset),
            );
        }

        return ExitCode::OK;
    }

    public function actionReplaceMetadata(): int
    {
        $this->stdout(
            'Deprecated: use `cloud/assets/repair/metadata` to repair object metadata instead.' . PHP_EOL,
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

        $values = [];

        if ($asset->getWidth() === null) {
            $values['width'] = $dimensions[0];
            $asset->setWidth($dimensions[0]);
        }

        if ($asset->getHeight() === null) {
            $values['height'] = $dimensions[1];
            $asset->setHeight($dimensions[1]);
        }

        if ($values === []) {
            return $dimensions;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::ASSETS, $values, ['id' => $asset->id])
            ->execute();

        return $dimensions;
    }

    protected function repairAssetSize(Asset $asset): ?int
    {
        try {
            $size = $asset->getVolume()->getFileSize($asset->getPath());
        } catch (Throwable) {
            return null;
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::ASSETS, [
                'size' => $size,
            ], ['id' => $asset->id])
            ->execute();

        $asset->size = $size;

        return $size;
    }

    protected function warnIfCraftStreamDimensionFixMissing(): void
    {
        $craftVersion = Craft::$app->getVersion();
        $fixedVersion = self::CRAFT_STREAM_DIMENSION_FIX_VERSION[explode('.', $craftVersion)[0] ?? ''] ?? null;

        if ($fixedVersion === null || version_compare($craftVersion, $fixedVersion, '>=')) {
            return;
        }

        $this->stdout(
            "Craft CMS {$fixedVersion}+ detects more image dimensions by stream; upgrade if skips persist." . PHP_EOL,
            Console::FG_YELLOW,
        );
    }

    protected function repairAssetObjectMetadata(Asset $asset): void
    {
        $fs = $asset->getVolume()->getFs();

        if (!$fs instanceof Fs) {
            throw new Exception('Invalid filesystem type.');
        }

        $path = $asset->getPath();

        $config = [
            'ContentType' => FileHelper::getMimeType($path),
            'MetadataDirective' => 'REPLACE',
        ];

        $fs->replaceMetadata($path, $config);
    }
}
