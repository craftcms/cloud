<?php

namespace craft\cloud\ops;

use Craft;
use craft\helpers\Json;
use Illuminate\Support\Collection;
use League\Uri\Components\HierarchicalPath;

class Composer
{
    public static function getPluginAliases(): Collection
    {
        $pluginInfo = Craft::$app->getPlugins()->getComposerPluginInfo();

        return Collection::make($pluginInfo)
            ->flatMap(fn(array $plugin) => $plugin['aliases'] ?? []);
    }

    public static function getModuleAliases(): Collection
    {
        $data = Json::decode(file_get_contents(Craft::$app->getComposer()->getLockPath()));
        $packages = new Collection($data['packages'] ?? null);

        return $packages
            ->flatMap(function(array $package) {
                $packageName = $package['name'] ?? null;
                $packageType = $package['type'] ?? null;

                if (
                    !$packageName ||
                    !$packageType ||
                    !preg_match('/^(craft|yii)/', $packageType)
                ) {
                    return null;
                }

                $basePath = HierarchicalPath::fromAbsolute(
                    Craft::$app->getVendorPath(),
                    $packageName,
                );

                return static::psr4ToAliases(
                    $package['autoload']['psr-4'] ?? [],
                    $basePath,
                );
            });
    }

    public static function getRootAliases(): Collection
    {
        $jsonPath = Craft::$app->getComposer()->getJsonPath();
        $root = dirname($jsonPath);
        $data = Json::decode(file_get_contents($jsonPath));

        return static::psr4ToAliases(
            $data['autoload']['psr-4'] ?? [],
            $root,
        );
    }

    protected static function psr4ToAliases(iterable $psr4, string $basePath): Collection
    {
        return Collection::make($psr4)
            ->mapWithKeys(function($path, $namespace) use ($basePath) {

                // Yii doesn't support aliases that point to multiple base paths
                if (is_array($path)) {
                    return null;
                }

                $normalizedPath = HierarchicalPath::new($path);

                if (!$normalizedPath->isAbsolute()) {
                    $normalizedPath = HierarchicalPath::fromAbsolute(
                        $basePath,
                        $path,
                    );
                }

                $alias = '@' . str_replace('\\', '/', trim($namespace, '\\'));
                $normalizedPath = $normalizedPath->withoutTrailingSlash()->value();

                return [$alias => $normalizedPath];
            });
    }
}
