<?php

namespace craft\cloud;

use Craft;
use craft\base\Event;
use craft\base\Model;
use craft\cloud\fs\AssetsFs;
use craft\cloud\imagetransforms\ImageTransformBehavior;
use craft\cloud\imagetransforms\ImageTransformer;
use craft\cloud\signing\RequestSigner;
use craft\cloud\signing\UrlSigner;
use craft\cloud\twig\TwigExtension;
use craft\cloud\web\assets\assetthumbfallback\AssetThumbFallbackAsset;
use craft\cloud\web\assets\uploader\UploaderAsset;
use craft\cloud\web\ResponseEventHandler;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\DefineAssetUrlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineRulesEvent;
use craft\events\GenerateTransformEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\log\MonologTarget;
use craft\models\ImageTransform as CraftImageTransform;
use craft\services\Assets as AssetsService;
use craft\services\Fs as FsService;
use craft\services\ImageTransforms;
use craft\web\Application as WebApplication;
use craft\web\View;
use Illuminate\Support\Collection;
use Psr\Log\LogLevel;
use samdark\log\PsrMessage;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\log\Logger;

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

    public static function log(int $level, string $message, array $context = []): void
    {
        Craft::getLogger()->log(new PsrMessage($message, $context), $level, self::class);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(Logger::LEVEL_INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(Logger::LEVEL_WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(Logger::LEVEL_ERROR, $message, $context);
    }

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
            'requestSigner' => fn() => new RequestSigner(
                signingKey: $this->getConfig()->signingKey ?? '',
            ),
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
                    if (!$this->shouldUseAssetCdnTransform($event)) {
                        return;
                    }

                    try {
                        $event->url = (new ImageTransformer())->getTransformUrl(
                            $event->asset,
                            $event->transform,
                            true,
                        );
                    } catch (NotSupportedException) {
                        return;
                    }
                }
            );

            Event::on(
                Asset::class,
                Asset::EVENT_BEFORE_DEFINE_URL,
                function(DefineAssetUrlEvent $event) {
                    if (
                        $event->url !== null ||
                        $event->asset->kind !== Asset::KIND_PDF ||
                        !$event->transform ||
                        !$this->isRemoteCloudAsset($event->asset)
                    ) {
                        return;
                    }

                    try {
                        $event->url = (new ImageTransformer())->getTransformUrl(
                            $event->asset,
                            $event->transform,
                            true,
                        );
                    } catch (NotSupportedException) {
                        return;
                    }
                }
            );

            Event::on(
                AssetsService::class,
                AssetsService::EVENT_DEFINE_THUMB_URL,
                function(DefineAssetThumbUrlEvent $event) {
                    if (
                        $event->url !== null ||
                        $event->asset->kind !== Asset::KIND_PDF ||
                        !$this->isRemoteCloudAsset($event->asset)
                    ) {
                        return;
                    }

                    try {
                        $event->url = (new ImageTransformer())->getTransformUrl(
                            $event->asset,
                            new CraftImageTransform([
                                'width' => $event->width,
                                'height' => $event->height,
                                'mode' => 'crop',
                            ]),
                            true,
                        );
                    } catch (NotSupportedException) {
                        return;
                    }
                }
            );

            if ($app->getRequest()->getIsCpRequest()) {
                $app->getView()->registerAssetBundle(AssetThumbFallbackAsset::class);
                $app->getView()->registerAssetBundle(UploaderAsset::class);
            }
        }
    }

    protected function shouldUseAssetCdnTransform(GenerateTransformEvent $event): bool
    {
        if (!$event->transform || !$this->isRemoteCloudAsset($event->asset)) {
            return false;
        }

        if (!(Craft::$app instanceof WebApplication)) {
            return true;
        }

        // The image editor reads raw source pixels for save/crop math.
        return Craft::$app->getRequest()->getActionSegments() !== ['assets', 'edit-image'];
    }

    private function isRemoteCloudAsset(Asset $asset): bool
    {
        $assetFs = $asset->getVolume()->getFs();

        return $assetFs instanceof AssetsFs && !$assetFs->useLocalFs;
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

            (new ResponseEventHandler())->handle();
        }
    }

    protected function registerGlobalEventHandlers(): void
    {
        Event::on(
            CraftImageTransform::class,
            Model::EVENT_DEFINE_BEHAVIORS,
            static function(DefineBehaviorsEvent $event) {
                $event->behaviors['cloud'] = ImageTransformBehavior::class;
            }
        );

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

    public function getRequestSigner(): RequestSigner
    {
        return $this->get('requestSigner');
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
        self::info("phpMaxMemoryLimit set to $memoryLimit");

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
