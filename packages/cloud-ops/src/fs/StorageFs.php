<?php

namespace craft\cloud\ops\fs;

use League\Uri\Contracts\SegmentedPathInterface;

class StorageFs extends Fs
{
    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('storage');
    }
}
