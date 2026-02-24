<?php

namespace craft\cloud;

use Craft;
use craft\config\BaseConfig;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\helpers\DateTimeHelper;
use League\Uri\Contracts\UriInterface;
use League\Uri\Uri;

/**
 * @method static devMode(bool $value)
 * @method static s3ClientOptions(array $options)
 * @method static region(?string $value)
 */
class Config extends BaseConfig
{
    public ?string $artifactBaseUrl = null;
    public string $cdnBaseUrl = 'https://cdn.craft.cloud';
    public bool $gzipResponse = true;
    public ?string $sqsUrl = null;
    public ?string $projectId = null;
    public ?string $environmentId = null;
    public ?string $buildId = 'current';
    public ?string $accessKey = null;
    public ?string $accessSecret = null;
    public ?string $accessToken = null;
    public ?string $redisUrl = null;
    public ?string $signingKey = null;
    public ?string $previewDomain = null;
    public bool $useQueue = true;
    public int $staticCacheDuration = DateTimeHelper::SECONDS_YEAR;
    public int $staticCacheStaleWhileRevalidateDuration = DateTimeHelper::SECONDS_HOUR;
    public ?string $storageEndpoint = null;
    public bool $useAssetCdn = true;
    public ?string $logLevel = null;
    private bool $devMode = false;
    private ?string $region = null;
    private array $s3ClientOptions = [];

    public function init(): void
    {
        if (!Helper::isCraftCloud()) {
            $this->gzipResponse = false;
            $this->useAssetCdn = false;
            $this->useQueue = false;
        }
    }

    public function attributeLabels(): array
    {
        return [
            'projectId' => Craft::t('app', 'Project ID'),
            'environmentId' => Craft::t('app', 'Environment ID'),
            'buildId' => Craft::t('app', 'Build ID'),
        ];
    }

    // Match fluent config setter convention
    public function __call($name, $params)
    {
        if (property_exists($this, $name)) {
            $this->$name = $params[0];

            return $this;
        }

        return parent::__call($name, $params);
    }

    public function getS3ClientOptions(): array
    {
        return $this->s3ClientOptions + array_filter([
            'use_path_style_endpoint' => (bool) $this->storageEndpoint,
            'endpoint' => $this->storageEndpoint,
        ]);
    }

    public function setS3ClientOptions(array $s3ClientOptions): static
    {
        $this->s3ClientOptions = $s3ClientOptions;

        return $this;
    }

    public function getDevMode(): bool
    {
        return App::env('CRAFT_CLOUD_DEV_MODE') ?? Craft::$app->getConfig()->getGeneral()->devMode;
    }

    public function setDevMode(bool $value): static
    {
        $this->devMode = $value;

        return $this;
    }

    /**
     * Technically, this is the limit of the combined request and response.
     * @see  https://docs.aws.amazon.com/lambda/latest/dg/gettingstarted-limits.html#function-configuration-deployment-and-execution
     */
    public function getMaxBytes(): float|int
    {
        return ConfigHelper::sizeInBytes(
            ini_get('upload_max_filesize'),
        );
    }

    public function getMaxSeconds(): int
    {
        return (int) ini_get('max_execution_time') ?: 900;
    }

    public function getRegion(): ?string
    {
        return App::env('CRAFT_CLOUD_REGION') ?? $this->region ?? App::env('AWS_REGION');
    }

    public function setRegion(?string $value): static
    {
        $this->region = $value;

        return $this;
    }

    public function getShortEnvironmentId(): ?string
    {
        return $this->environmentId
            ? substr($this->environmentId, 0, 8)
            : null;
    }

    public function getPreviewDomainUrl(): ?UriInterface
    {
        if (!$this->previewDomain) {
            return null;
        }

        return (Uri::new())
            ->withHost($this->previewDomain)
            ->withScheme('https');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [
            ['environmentId', 'projectId'],
            'required',
            'when' => fn(Config $model) => $model->useAssetCdn,
        ];

        return $rules;
    }

    public static function create(array $config = []): static
    {
        $fileConfig = Craft::$app->getConfig()->getConfigFromFile('cloud');

        $configObj = is_array($fileConfig)
            ? Craft::createObject(['class' => Config::class] + $fileConfig)
            : $fileConfig;

        /** @var static $configObj */
        $configObj = Craft::configure(
            $configObj,
            $config + \craft\helpers\App::envConfig(Config::class, 'CRAFT_CLOUD_'),
        );

        return $configObj;
    }
}
