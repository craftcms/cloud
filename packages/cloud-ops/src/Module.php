<?php

namespace craft\cloud\ops;

use Craft;
use craft\base\Event;
use craft\base\Model;
use craft\cloud\ops\fs\AssetsFs;
use craft\cloud\ops\imagetransforms\ImageTransformer;
use craft\cloud\ops\twig\TwigExtension;
use craft\cloud\ops\web\assets\uploader\UploaderAsset;
use craft\cloud\ops\web\ResponseEventHandler;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\events\DefineRulesEvent;
use craft\events\GenerateTransformEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\models\ImageTransform;
use craft\services\Fs as FsService;
use craft\services\ImageTransforms;
use craft\services\Plugins;
use craft\web\Application as WebApplication;
use craft\web\View;
use Illuminate\Support\Collection;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

class Module extends \yii\base\Module implements BootstrapInterface
{
    private Config $_config;
    private bool $_runtimeInitialized = false;

    public function bootstrap($app): void
    {
        self::setInstance($this);

        if (!$app instanceof ConsoleApplication && !$app instanceof WebApplication) {
            return;
        }

        $isConsole = $app->getRequest()->getIsConsoleRequest();
        $this->controllerNamespace = $isConsole
            ? 'craft\\cloud\\ops\\cli\\controllers'
            : 'craft\\cloud\\ops\\controllers';

        // Register as 'cloud' immediately, so controllers can be found.
        $this->id = 'cloud';
        $app->setModule('cloud', $this);

        $this->initializeRuntime();

        // Override controllers from any loaded plugin that owns the `cloud` handle.
        $app->getPlugins()->on(Plugins::EVENT_AFTER_LOAD_PLUGINS, function() use ($app) {
            $cloudPlugin = $app->getPlugins()->getPlugin('cloud');

            if ($cloudPlugin !== null) {
                $cloudPlugin->controllerNamespace = $this->controllerNamespace;
            }
        });

        if (self::isCraftCloud()) {
            $this->bootstrapCloud($app);
        }
    }

    public static function instance(): self
    {
        $instance = self::getInstance();

        if (!$instance instanceof self) {
            throw new InvalidConfigException('The Craft Cloud module has not been bootstrapped.');
        }

        return $instance;
    }

    public static function isCraftCloud(): bool
    {
        return App::env('CRAFT_CLOUD') ?? App::env('AWS_LAMBDA_RUNTIME_API') ?? false;
    }

    public function getConfig(): Config
    {
        if (isset($this->_config)) {
            return $this->_config;
        }

        $this->_config = Config::create();

        return $this->_config;
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

    protected function initializeRuntime(): void
    {
        if ($this->_runtimeInitialized) {
            return;
        }

        $this->_runtimeInitialized = true;

        $this->registerComponents();
        // $this->registerEventHandlers();
        $this->registerTwigExtension();

        if (self::isCraftCloud()) {
            // $this->initCloud();
        }

        if ($this->getConfig()->useAssetCdn) {
            // $this->initAssetCdn();
        }
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
            'name' => 'cloud',
            'level' => App::devMode() ? \Psr\Log\LogLevel::INFO : \Psr\Log\LogLevel::WARNING,
            'categories' => ['craft\cloud\*'],
        ]);
    }

    protected function getMaxExecutionSeconds(): int
    {
        return Craft::$app->getRequest()->getIsConsoleRequest() ? 897 : 60;
    }

    protected function registerComponents(): void
    {
        $this->setComponents([
            'staticCache' => StaticCache::class,
            'urlSigner' => fn() => new UrlSigner(
                signingKey: $this->getConfig()->signingKey ?? '',
            ),
            'esi' => fn() => new Esi(
                urlSigner: $this->getUrlSigner(),
                useEsi: self::isCraftCloud(),
            ),
        ]);

        // Replace ImageTransform with cloud ImageTransform via DI
        Craft::$container->set(ImageTransform::class, \craft\cloud\ops\imagetransforms\ImageTransform::class);
    }

    protected function registerEventHandlers(): void
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
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['cloud'] = dirname((new \ReflectionClass(Config::class))->getFileName()) . '/templates';
            }
        );
    }

    protected function registerTwigExtension(): void
    {
        Craft::$app->getView()->registerTwigExtension(new TwigExtension());
    }

    protected function initCloud(): void
    {
        $this->getStaticCache()->registerEventHandlers();

        Event::on(
            Asset::class,
            Model::EVENT_DEFINE_RULES,
            function(DefineRulesEvent $event) {
                $event->rules = $this->removeAttributeFromRules($event->rules, 'tempFilePath');
            }
        );

        if (Craft::$app instanceof \craft\web\Application) {
            // Important this gets called last so multi-value headers aren't prematurely joined
            (new ResponseEventHandler())->handle();
        }
    }

    protected function initAssetCdn(): void
    {
        Craft::$app->getImages()->supportedImageFormats = ImageTransformer::SUPPORTED_IMAGE_FORMATS;

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

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Craft::$app->getView()->registerAssetBundle(UploaderAsset::class);
        }
    }

    protected function removeAttributeFromRules(array $rules, string $attributeToRemove): array
    {
        return Collection::make($rules)
            ->map(fn($rule) => $this->removeAttributeFromRule($rule, $attributeToRemove))
            ->all();
    }

    protected function removeAttributeFromRule(array $rule, string $attributeToRemove): array
    {
        $attributes = Collection::wrap($rule[0])
            ->reject(fn($attribute) => $attribute === $attributeToRemove);

        $rule[0] = $attributes->all();

        return $rule;
    }
}
