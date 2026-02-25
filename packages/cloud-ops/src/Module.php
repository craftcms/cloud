<?php

namespace craft\cloud\ops;

use Craft;
use craft\console\Application as ConsoleApplication;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\services\Plugins;
use craft\web\Application as WebApplication;
use Illuminate\Support\Collection;
use yii\base\BootstrapInterface;
use yii\helpers\Inflector;

class Module extends \yii\base\Module implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        self::setInstance($this);

        $app->getPlugins()->on(Plugins::EVENT_AFTER_LOAD_PLUGINS, function() use ($app) {
            $cloudModule = $app->getModule('cloud');

            if ($cloudModule === null) {
                Craft::debug('Cloud module was not found; skipping cloud-ops controller map injection.', __METHOD__);
                return;
            }

            $this->injectCloudControllerMap($app, $cloudModule);
        });

        if (self::isCraftCloud() && ($app instanceof ConsoleApplication || $app instanceof WebApplication)) {
            $this->bootstrapCloud($app);
        }
    }

    protected function injectCloudControllerMap(ConsoleApplication|WebApplication $app, \yii\base\Module $cloudModule): void
    {
        $isConsoleRequest = $app->getRequest()->getIsConsoleRequest();
        $controllerDirectory = $isConsoleRequest ? 'cli/controllers' : 'controllers';
        $controllerNamespace = $isConsoleRequest
            ? 'craft\\cloud\\ops\\cli\\controllers\\'
            : 'craft\\cloud\\ops\\controllers\\';
        $controllerPath = $this->getBasePath() . DIRECTORY_SEPARATOR . $controllerDirectory;

        if (!is_dir($controllerPath)) {
            return;
        }

        foreach (glob($controllerPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $controllerFile) {
            $className = pathinfo($controllerFile, PATHINFO_FILENAME);
            $controllerClass = $controllerNamespace . $className;

            if (!str_ends_with($className, 'Controller') || !class_exists($controllerClass)) {
                continue;
            }

            if (!is_subclass_of($controllerClass, \yii\base\Controller::class)) {
                continue;
            }

            $reflectionClass = new \ReflectionClass($controllerClass);

            if ($reflectionClass->isAbstract()) {
                continue;
            }

            $controllerId = Inflector::camel2id(substr($className, 0, -10));
            $cloudModule->controllerMap[$controllerId] = $controllerClass;
        }
    }

    public static function isCraftCloud(): bool
    {
        return App::env('CRAFT_CLOUD') ?? App::env('AWS_LAMBDA_RUNTIME_API') ?? false;
    }

    protected function bootstrapCloud(ConsoleApplication|WebApplication $app): void
    {
        $this->configureExecutionLimits($app);
        $this->configureRequest($app);
        $this->configureLogging($app);
    }

    protected function configureExecutionLimits(ConsoleApplication|WebApplication $app): void
    {
        ini_set('max_execution_time', (string) $this->getMaxExecutionSeconds());

        $memoryLimit = ConfigHelper::sizeInBytes(ini_get('memory_limit'))
            - ConfigHelper::sizeInBytes($app->getErrorHandler()->memoryReserveSize);

        Craft::$app->getConfig()->getGeneral()->phpMaxMemoryLimit((string) $memoryLimit);
    }

    protected function configureRequest(ConsoleApplication|WebApplication $app): void
    {
        if ($app instanceof WebApplication) {
            Craft::setAlias('@web', $app->getRequest()->getHostInfo());

            $app->getRequest()->secureHeaders = Collection::make($app->getRequest()->secureHeaders)
                ->reject(fn(string $header) => $header === 'X-Forwarded-Host')
                ->all();
        }
    }

    protected function configureLogging(ConsoleApplication|WebApplication $app): void
    {
        $app->getLog()->targets[] = Craft::createObject([
            'class' => \craft\log\MonologTarget::class,
            'name' => 'cloud-ops',
            'level' => App::devMode() ? \Psr\Log\LogLevel::INFO : \Psr\Log\LogLevel::WARNING,
            'categories' => ['craft\cloud\ops\*'],
        ]);
    }

    protected function getMaxExecutionSeconds(): int
    {
        return Craft::$app->getRequest()->getIsConsoleRequest() ? 897 : 60;
    }
}
