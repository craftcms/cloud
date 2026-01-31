<?php

namespace craft\cloud\fs;

use craft\cloud\Plugin;
use League\Uri\Contracts\SegmentedPathInterface;

abstract class BuildsFs extends Fs
{
    public bool $hasUrls = true;
    protected ?string $expires = '1 years';

    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()
            ->append('builds')
            ->append(Plugin::getInstance()->getConfig()->buildId);
    }
}
