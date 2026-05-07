<?php

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Option;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __FILE__,
    ]);

    $ecsConfig->parameters()->set(Option::PARALLEL, false);
    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
