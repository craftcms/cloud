<?php

namespace craft\cloud\fs;

use League\Uri\Contracts\SegmentedPathInterface;

class TmpFs extends StorageFs
{
    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('tmp');
    }
}
