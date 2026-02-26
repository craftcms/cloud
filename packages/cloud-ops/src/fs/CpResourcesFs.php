<?php

namespace craft\cloud\ops\fs;

use League\Uri\Contracts\SegmentedPathInterface;

class CpResourcesFs extends BuildsFs
{
    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('cpresources');
    }
}
