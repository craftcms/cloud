<?php

namespace craft\cloud\fs;

use craft\cloud\Plugin;
use League\Uri\Contracts\SegmentedPathInterface;

class AssetsFs extends Fs
{
    protected ?string $expires = '1 years';
    public ?string $localFsPath = '@webroot/uploads';
    public ?string $localFsUrl = '/uploads';

    public function init(): void
    {
        parent::init();
        $this->useLocalFs = !Plugin::getInstance()->getConfig()->useAssetCdn;
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
