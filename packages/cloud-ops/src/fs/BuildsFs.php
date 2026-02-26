<?php

namespace craft\cloud\ops\fs;

use craft\cloud\ops\Config;
use League\Uri\Contracts\SegmentedPathInterface;

abstract class BuildsFs extends Fs
{
    public bool $hasUrls = true;
    protected ?string $expires = '1 years';

    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()
            ->append('builds')
            ->append(Config::create()->buildId);
    }
}
