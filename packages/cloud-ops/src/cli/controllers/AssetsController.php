<?php

namespace craft\cloud\ops\cli\controllers;

use craft\cloud\ops\fs\Fs;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use yii\base\Exception;
use yii\console\ExitCode;

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
            'replace-metadata' => ['volume', 'assetId'],
            default => []
        });
    }

    public function actionReplaceMetadata(): int
    {
        $assets = Asset::find()
            ->volume($this->volume)
            ->id($this->assetId)
            ->collect();

        $assets->each(function(Asset $asset) {
            $this->do(
                "Replacing metadata for `$asset->path`",
                fn() => $this->replaceAssetMetadata($asset),
            );
        });

        return ExitCode::OK;
    }

    protected function replaceAssetMetadata(Asset $asset): void
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
