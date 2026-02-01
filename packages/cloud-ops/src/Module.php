<?php

namespace craft\cloud\ops;

use Craft;
use craft\console\Application as ConsoleApplication;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\web\Application as WebApplication;
use Illuminate\Support\Collection;
use yii\base\BootstrapInterface;

class Module extends \yii\base\Module implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        if (!$this->id) {
            $this->id = 'cloud-ops';
        }

        self::setInstance($this);

        $this->controllerNamespace = $app->getRequest()->getIsConsoleRequest()
            ? 'craft\\cloud\\ops\\cli\\controllers'
            : null;

        $app->setModule($this->id, $this);

        if (self::isCraftCloud() && ($app instanceof ConsoleApplication || $app instanceof WebApplication)) {
            $this->bootstrapCloud($app);
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
