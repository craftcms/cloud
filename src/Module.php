<?php

namespace craft\cloud;

use Craft;
use craft\base\Event;
use craft\base\Model;
use craft\cloud\fs\AssetsFs;
use craft\cloud\imagetransforms\ImageTransformer;
use craft\cloud\twig\TwigExtension;
use craft\cloud\web\assets\uploader\UploaderAsset;
use craft\cloud\web\ResponseEventHandler;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\events\DefineRulesEvent;
use craft\events\GenerateTransformEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\log\MonologTarget;
use craft\services\Fs as FsService;
use craft\services\ImageTransforms;
use craft\web\Application as WebApplication;
use craft\web\View;
use Illuminate\Support\Collection;
use Psr\Log\LogLevel;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

/**
 * @property ?string $id When auto-bootstrapped as an extension, this can be `null`.
 */
class Module extends \yii\base\Module implements \yii\base\BootstrapInterface
{
    /*
     * Backup insurance limit; gateway limit may be shorter.
     */
    private const MAX_EXECUTION_SECONDS_WEB = 60;

    /**
     * Include buffer so PHP times out before Lambda.
     * @see \craft\cloud\bref\craft\CraftCliEntrypoint::PROCESS_TIMEOUT_SECONDS
     */
    private const MAX_EXECUTION_SECONDS_CLI = 900 - 10;

    private Config $_config;

    /**
     * @throws InvalidConfigException
     * @param WebApplication|ConsoleApplication $app
     * @inheritDoc
     */
    public function bootstrap($app): void
    {
        $this->id = $this->id ?? 'cloud';

        // Set instance early so our dependencies can use it
        self::setInstance($this);

        $this->controllerNamespace = $app->getRequest()->getIsConsoleRequest()
            ? 'craft\\cloud\\cli\\controllers'
            : 'craft\\cloud\\controllers';

        $this->registerGlobalEventHandlers();
        $this->validateConfig();

        // Required for controllers to be found
        $app->setModule($this->id, $this);

        $app->getView()->registerTwigExtension(new TwigExtension());

        Craft::setAlias('@artifactBaseUrl', Helper::artifactUrl());

        $this->setComponents([
            'staticCache' => StaticCache::class,
            'urlSigner' => fn() => new UrlSigner(
                signingKey: $this->getConfig()->signingKey ?? '',
            ),
            'esi' => fn() => new Esi(
                urlSigner: $this->getUrlSigner(),
                useEsi: Helper::isCraftCloud(),
            ),
        ]);

        if (Helper::isCraftCloud()) {
            $this->bootstrapCloud($app);
        }

        if ($this->getConfig()->useAssetCdn) {
            $app->getImages()->supportedImageFormats = ImageTransformer::SUPPORTED_IMAGE_FORMATS;

            Event::on(
                Asset::class,
                Asset::EVENT_BEFORE_GENERATE_TRANSFORM,
                function(GenerateTransformEvent $event) {
                    if (!$event->transform || !$event->asset?->fs instanceof AssetsFs) {
                        return;
                    }

                    try {
                        $event->url = (new ImageTransformer())->getTransformUrl(
                            $event->asset,
                            $event->transform,
                            true,
                        );
                    } catch (NotSupportedException) {
                        Craft::info("Transforms not supported for {$event->asset->getPath()}", __METHOD__);
                    }
                }
            );

            if ($app->getRequest()->getIsCpRequest()) {
                $app->getView()->registerAssetBundle(UploaderAsset::class);
            }
        }
    }

    public function getConfig(): Config
    {
        if (isset($this->_config)) {
            return $this->_config;
        }

        $fileConfig = Craft::$app->getConfig()->getConfigFromFile($this->id);

        /** @var Config $config */
        $config = is_array($fileConfig)
            ? Craft::createObject(['class' => Config::class] + $fileConfig)
            : $fileConfig;

        $this->_config = Craft::configure($config, App::envConfig(Config::class, 'CRAFT_CLOUD_'));

        return $this->_config;
    }

    protected function bootstrapCloud(ConsoleApplication|WebApplication $app): void
    {
        ini_set(
            'max_execution_time',
            (string) $this->getMaxExecutionSeconds(),
        );

        // Set Craft memory limit to just below PHP's limit
        $this->setMemoryLimit(
            ini_get('memory_limit'),
            $app->getErrorHandler()->memoryReserveSize,
        );

        $this->registerCloudEventHandlers();

        $app->getLog()->targets[] = Craft::createObject([
            'class' => MonologTarget::class,
            'name' => 'cloud',
            'level' => $this->getConfig()->logLevel ?? (App::devMode() ? LogLevel::INFO : LogLevel::WARNING),
            'categories' => ['craft\cloud\*'],
        ]);

        if ($app instanceof WebApplication) {
            Craft::setAlias('@web', $app->getRequest()->getHostInfo());

            $app->getRequest()->secureHeaders = Collection::make($app->getRequest()->secureHeaders)
                ->reject(fn(string $header) => $header === 'X-Forwarded-Host')
                ->all();

            // Important this gets called last so multi-value headers aren't prematurely joined
            (new ResponseEventHandler())->handle();
        }
    }

    protected function registerGlobalEventHandlers(): void
    {
        Event::on(
            ImageTransforms::class,
            ImageTransforms::EVENT_REGISTER_IMAGE_TRANSFORMERS,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = ImageTransformer::class;
            }
        );

        Event::on(
            FsService::class,
            FsService::EVENT_REGISTER_FILESYSTEM_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = AssetsFs::class;
            }
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $e) {
                $e->roots[$this->id] = sprintf('%s/templates', $this->getBasePath());
            }
        );
    }

    protected function registerCloudEventHandlers(): void
    {
        $this->getStaticCache()->registerEventHandlers();

        Event::on(
            Asset::class,
            Model::EVENT_DEFINE_RULES,
            function(DefineRulesEvent $e) {
                $e->rules = $this->removeAttributeFromRules($e->rules, 'tempFilePath');
            }
        );
    }

    protected function validateConfig(): void
    {
        $config = $this->getConfig();

        if (!$config->validate()) {
            $firstErrors = $config->getFirstErrors();
            throw new InvalidConfigException(reset($firstErrors) ?: '');
        }
    }

    public function getStaticCache(): StaticCache
    {
        return $this->get('staticCache');
    }

    public function getUrlSigner(): UrlSigner
    {
        return $this->get('urlSigner');
    }

    public function getEsi(): Esi
    {
        return $this->get('esi');
    }

    private function removeAttributeFromRule(array $rule, string $attributeToRemove): array
    {
        $attributes = Collection::wrap($rule[0])
            ->reject(fn($attribute) => $attribute === $attributeToRemove);

        // We may end up with a rule with an empty array of attributes.
        // We still need to keep that rule around so any potential
        // scenarios get defined from the 'on' key.
        $rule[0] = $attributes->all();

        return $rule;
    }

    private function removeAttributeFromRules(array $rules, string $attributeToRemove): array
    {
        return Collection::make($rules)
            ->map(fn($rule) => $this->removeAttributeFromRule($rule, $attributeToRemove))
            ->all();
    }

    private function setMemoryLimit(int|string $limit, int|string $offset = 0): int|float
    {
        $memoryLimit = ConfigHelper::sizeInBytes($limit) - ConfigHelper::sizeInBytes($offset);
        Craft::$app->getConfig()->getGeneral()->phpMaxMemoryLimit((string) $memoryLimit);
        Craft::info("phpMaxMemoryLimit set to $memoryLimit", __METHOD__);

        return $memoryLimit;
    }

    private function getMaxExecutionSeconds(): int
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return self::MAX_EXECUTION_SECONDS_CLI;
        }

        return self::MAX_EXECUTION_SECONDS_WEB;
    }
}
