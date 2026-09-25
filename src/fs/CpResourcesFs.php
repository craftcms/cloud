<?php

namespace craft\cloud\fs;

use craft\cloud\Module;
use League\Uri\Contracts\SegmentedPathInterface;

class CpResourcesFs extends BuildsFs
{
    public function init(): void
    {
        parent::init();
        $this->baseUrl = Module::getInstance()->getConfig()->resourceBaseUrl;
    }

    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('cpresources');
    }
}
