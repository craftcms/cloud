<?php

namespace craft\cloud\ops\fs;

use craft\cloud\ops\Helper;
use craft\cloud\Plugin;
use League\Uri\Contracts\SegmentedPathInterface;

class BuildArtifactsFs extends BuildsFs
{
    public bool $hasUrls = true;
    public ?string $localFsPath = '@webroot';
    public ?string $localFsUrl = '@web';

    public function init(): void
    {
        parent::init();
        $this->useLocalFs = !Helper::isCraftCloud();
        $this->baseUrl = Plugin::getInstance()->getConfig()->artifactBaseUrl;

        // Allow local override via config/env
        if ($this->baseUrl) {
            $this->localFsUrl = $this->baseUrl;
        }
    }

    public function createBucketPrefix(): SegmentedPathInterface
    {
        return parent::createBucketPrefix()->append('artifacts');
    }
}
