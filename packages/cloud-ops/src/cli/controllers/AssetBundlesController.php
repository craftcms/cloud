<?php

namespace craft\cloud\ops\cli\controllers;

use Craft;
use craft\cloud\ops\cli\AssetBundlePublisher;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Json;
use craft\web\assets\datepickeri18n\DatepickerI18nAsset;
use Illuminate\Support\Collection;
use League\Uri\Components\HierarchicalPath;
use ReflectionClass;
use yii\console\Exception;
use yii\console\ExitCode;
use yii\web\AssetBundle;

class AssetBundlesController extends Controller
{
    public bool $quiet = false;
    public ?string $to = null;

    public function init(): void
    {
        $this->to = $this->to ?? Craft::$app->getConfig()->getGeneral()->resourceBasePath;

        parent::init();
    }

    public function beforeAction($action): bool
    {
        // Don't allow if ephemeral, as the publish command won't create any files
        if (App::isEphemeral()) {
            throw new Exception('Asset bundle publishing is not supported in ephemeral environments.');
        }

        if (App::env('CRAFT_NO_DB')) {
            $this->getModuleAliases()
                ->merge($this->getPluginAliases())
                ->merge($this->getRootAliases())
                ->each(function($path, $alias) {
                    return Craft::setAlias($alias, $path);
                });
        }

        return true;
    }

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'publish-bundle' => [
                'quiet',
                'to',
            ],
            default => [
                'to',
            ],
        });
    }

    public function actionPublishBundle(string $className): int
    {
        try {
            $this->do("Publishing `$className` to `$this->to`", function() use ($className) {
                $rc = new ReflectionClass($className);

                if (!$rc->isSubclassOf(AssetBundle::class) || !$rc->isInstantiable()) {
                    // TODO: enhance \craft\console\Controller::do to return
                    // non-error responses (skip)
                    return;
                }

                // Set the language to a `web/assets/datepickeri18n/dist/datepicker-*.js` match so the entire directory is published
                if ($className === DatepickerI18nAsset::class) {
                    Craft::$app->language = 'en-GB';
                }

                /** @var AssetBundle $assetBundle */
                $assetBundle = Craft::createObject($className);

                $assetManagerClass = 'craft\\cloud\\web\\AssetManager';
                $config = ['basePath' => $this->to] + App::assetManagerConfig();

                if (class_exists($assetManagerClass)) {
                    $config['class'] = $assetManagerClass;
                }

                $assetManager = Craft::createObject($config);

                $assetBundle->publish($assetManager);
            });
        } catch (\Throwable $e) {
            if (!$this->quiet) {
                throw $e;
            }
        }

        return ExitCode::OK;
    }

    public function actionPublish(): int
    {
        $publisher = new AssetBundlePublisher();
        $publisher->to = $this->to;
        $publisher->wait();

        return ExitCode::OK;
    }

    private function getPluginAliases(): Collection
    {
        $pluginInfo = Craft::$app->getPlugins()->getComposerPluginInfo();

        return Collection::make($pluginInfo)
            ->flatMap(fn(array $plugin) => $plugin['aliases'] ?? []);
    }

    private function getModuleAliases(): Collection
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

                return $this->psr4ToAliases(
                    $package['autoload']['psr-4'] ?? [],
                    $basePath,
                );
            });
    }

    private function getRootAliases(): Collection
    {
        $jsonPath = Craft::$app->getComposer()->getJsonPath();
        $root = dirname($jsonPath);
        $data = Json::decode(file_get_contents($jsonPath));

        return $this->psr4ToAliases(
            $data['autoload']['psr-4'] ?? [],
            $root,
        );
    }

    private function psr4ToAliases(iterable $psr4, string $basePath): Collection
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
