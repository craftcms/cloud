<?php

namespace craft\cloud\ops\fs;

use craft\cloud\ops\Module;
use League\Uri\Contracts\SegmentedPathInterface;

class AssetsFs extends Fs
{
    protected ?string $expires = '1 years';
    public ?string $localFsPath = '@webroot/uploads';
    public ?string $localFsUrl = '/uploads';

    public function init(): void
    {
        parent::init();
        $this->useLocalFs = !Module::instance()->getConfig()->useAssetCdn;
    }

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return 'Craft Cloud';
    }

    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('assets');
    }
}
