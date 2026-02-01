<?php

namespace craft\cloud\fs;

use League\Uri\Contracts\SegmentedPathInterface;

class StorageFs extends Fs
{
    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('storage');
    }
}
