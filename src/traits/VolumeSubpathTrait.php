<?php

namespace craft\cloud\traits;

use craft\models\Volume;

trait VolumeSubpathTrait
{
    private function volumeSubpath(Volume $volume): string
    {
        return method_exists($volume, 'getSubpath') ? $volume->getSubpath() : '';
    }
}
