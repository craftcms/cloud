<?php

if (!function_exists('craft_modify_app_config')) {
    function craft_modify_app_config(array &$config, string $appType): void
    {
        $config = (new \craft\cloud\AppConfig($config, $appType))->getConfig();
    }
}
