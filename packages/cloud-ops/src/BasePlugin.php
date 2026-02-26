<?php

namespace craft\cloud\ops;

use Craft;
use craft\base\Event;
use craft\base\Model;
use craft\base\Plugin as CraftPlugin;
use craft\cloud\ops\fs\AssetsFs;
use craft\cloud\ops\imagetransforms\ImageTransformer;
use craft\cloud\ops\twig\TwigExtension;
use craft\cloud\ops\web\assets\uploader\UploaderAsset;
use craft\cloud\ops\web\ResponseEventHandler;
use craft\elements\Asset;
use craft\events\DefineRulesEvent;
use craft\events\GenerateTransformEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\models\ImageTransform;
use craft\services\Fs as FsService;
use craft\services\ImageTransforms;
use craft\web\View;
use yii\base\NotSupportedException;

/**
 * Craft Cloud Plugin
 *
 * @property-read StaticCache $staticCache
 * @property-read UrlSigner $urlSigner
 * @property-read Esi $esi
 */
abstract class BasePlugin extends CraftPlugin
{
    private Config $_config;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerComponents();
        $this->_registerEventHandlers();
        $this->_registerTwigExtension();
        $this->_setAliases();

        if (Helper::isCraftCloud()) {
            $this->_initCloud();
        }

        if ($this->getConfig()->useAssetCdn) {
            $this->_initAssetCdn();
        }
    }

    /**
     * Returns the plugin's configuration model.
     */
    public function getConfig(): Config
    {
        if (isset($this->_config)) {
            return $this->_config;
        }

        $this->_config = Config::create();

        return $this->_config;
    }

    /**
     * Returns the static cache component.
     */
    public function getStaticCache(): StaticCache
    {
        return $this->get('staticCache');
    }

    /**
     * Returns the URL signer component.
     */
    public function getUrlSigner(): UrlSigner
    {
        return $this->get('urlSigner');
    }

    /**
     * Returns the ESI component.
     */
    public function getEsi(): Esi
    {
        return $this->get('esi');
    }

    /**
     * Registers plugin components.
     */
    protected function _registerComponents(): void
    {
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

        // Replace ImageTransform with cloud ImageTransform via DI
        Craft::$container->set(ImageTransform::class, \craft\cloud\ops\imagetransforms\ImageTransform::class);
    }

    /**
     * Registers global event handlers.
     */
    protected function _registerEventHandlers(): void
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
                $e->roots['cloud'] = dirname((new \ReflectionClass(Config::class))->getFileName()) . '/templates';
            }
        );
    }

    /**
     * Registers the Twig extension.
     */
    protected function _registerTwigExtension(): void
    {
        Craft::$app->getView()->registerTwigExtension(new TwigExtension());
    }

    /**
     * Sets plugin aliases.
     */
    protected function _setAliases(): void
    {
        Craft::setAlias('@artifactBaseUrl', Helper::artifactUrl());
    }

    /**
     * Initializes Cloud-specific functionality.
     */
    protected function _initCloud(): void
    {
        $this->getStaticCache()->registerEventHandlers();

        Event::on(
            Asset::class,
            Model::EVENT_DEFINE_RULES,
            function(DefineRulesEvent $e) {
                $e->rules = $this->_removeAttributeFromRules($e->rules, 'tempFilePath');
            }
        );

        if (Craft::$app instanceof \craft\web\Application) {
            // Important this gets called last so multi-value headers aren't prematurely joined
            (new ResponseEventHandler())->handle();
        }
    }

    /**
     * Initializes Asset CDN functionality.
     */
    protected function _initAssetCdn(): void
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

    /**
     * Removes an attribute from validation rules.
     */
    protected function _removeAttributeFromRules(array $rules, string $attributeToRemove): array
    {
        return \Illuminate\Support\Collection::make($rules)
            ->map(fn($rule) => $this->_removeAttributeFromRule($rule, $attributeToRemove))
            ->all();
    }

    /**
     * Removes an attribute from a single validation rule.
     */
    protected function _removeAttributeFromRule(array $rule, string $attributeToRemove): array
    {
        $attributes = \Illuminate\Support\Collection::wrap($rule[0])
            ->reject(fn($attribute) => $attribute === $attributeToRemove);

        $rule[0] = $attributes->all();

        return $rule;
    }
}
