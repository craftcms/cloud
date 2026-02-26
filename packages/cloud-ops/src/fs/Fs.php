<?php

namespace craft\cloud\ops\fs;

use Aws\Credentials\Credentials;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\cloud\ops\Config;
use craft\cloud\ops\StaticCache;
use craft\cloud\ops\StaticCacheTag;
use craft\cloud\Plugin;
use craft\errors\FsException;
use craft\flysystem\base\FlysystemFs;
use craft\fs\Local;
use craft\helpers\App;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Collection;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\Visibility;
use League\Uri\Components\HierarchicalPath;
use League\Uri\Contracts\SegmentedPathInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Modifier;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * @property-read ?string $settingsHtml
 */
abstract class Fs extends FlysystemFs
{
    protected static bool $showUrlSetting = false;
    protected ?string $expires = null;
    protected ?Local $localFs = null;
    protected S3Client $client;
    public ?string $subpath = null;
    public ?string $localFsPath = null;
    public ?string $localFsUrl = null;
    public bool $useLocalFs = false;
    public ?string $baseUrl = null;

    /**
     * @inheritDoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['localFsPath'], 'required'];
        $rules[] = [
            'localFsUrl',
            'required',
            'when' => fn(self $fs) => $fs->hasUrls,
        ];

        return $rules;
    }

    protected function getLocalFs(): Local
    {
        $path = $this->localFsPath
            ? $this->createPath('')->prepend($this->localFsPath)
            : null;

        $this->localFs = $this->localFs ?? Craft::createObject([
            'class' => Local::class,
            'hasUrls' => $this->hasUrls,
            'path' => $path?->toString(),
            'url' => $this->localFsUrl,
        ]);

        return $this->localFs;
    }

    /**
     * This should never be null, as the Cloud resizer can render asset transforms for the CP,
     * even if `$hasUrls` is `false`.
     *
     * @inheritdoc
     */
    public function getRootUrl(): ?string
    {
        return $this->createUrl()->toString();
    }

    public function createUrl(string $path = ''): UriInterface
    {
        if ($this->useLocalFs) {
            return Modifier::from($this->getLocalFs()->getRootUrl() ?? '/')
                ->appendSegment($this->createPath($path))
                ->getUri();
        }

        $baseUrl = App::parseEnv($this->baseUrl);

        if ($baseUrl) {
            return Modifier::from($baseUrl)
                ->appendSegment($this->createPath($path))
                ->getUri();
        }

        return Modifier::from(Config::create()->cdnBaseUrl)
            ->appendSegment($this->createBucketPath($path))
            ->getUri();
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'localFsPath' => Craft::t('app', 'Base Path'),
            'localFsUrl' => Craft::t('app', 'Base URL'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => [
                'subpath',
                'baseUrl',
                'localFsPath',
                'localFsUrl',
            ],
        ];

        return $behaviors;
    }

    /**
     * @inheritDoc
     */
    public function settingsAttributes(): array
    {
        return array_merge(parent::settingsAttributes(), [
            'expires',
            'subpath',
            'baseUrl',
            'localFsPath',
            'localFsUrl',
        ]);
    }

    public function getExpires(): ?string
    {
        return $this->expires;
    }

    public function setExpires(null|string|array $expires): void
    {
        $this->expires = is_array($expires) ? $this->normalizeExpires($expires) : $expires;
    }

    protected function normalizeExpires(array $expires): ?string
    {
        $amount = (int)$expires['amount'];
        $period = $expires['period'];

        if (!$amount || !$period) {
            return null;
        }

        return "$amount $period";
    }

    /**
     * @inheritDoc
     */
    protected function createAdapter(): AwsS3V3Adapter
    {
        return new AwsS3V3Adapter(
            client: $this->getClient(),
            bucket: $this->getBucketName(),
            prefix: $this->createBucketPath('')->toString(),
        );
    }

    /**
     * @inheritDoc
     */
    protected function invalidateCdnPath(string $path): bool
    {
        try {
            $prefix = StaticCache::CDN_PREFIX . Plugin::getInstance()->getConfig()->environmentId . ':';
            $tag = StaticCacheTag::create($this->createBucketPath($path)->toString())
                ->minify(false)
                ->withPrefix($prefix);

            Plugin::getInstance()->getStaticCache()->purgeTags($tag);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    protected function addFileMetadataToConfig(array $config): array
    {
        if (!empty($this->getExpires()) && DateTimeHelper::isValidIntervalString($this->getExpires())) {
            $expires = new DateTime();
            $now = new DateTime();
            $expires->modify('+' . $this->getExpires());
            $diff = (int)$expires->format('U') - (int)$now->format('U');

            // Setting this in metadata instead of `CacheControl` because
            // `CacheControl` is not respected by S3 when using presigned PUT URLs.
            // @see https://github.com/aws/aws-sdk-php/issues/1691
            $config['Metadata']['max-age'] = $diff;
        }

        $config['Metadata']['visibility'] = $this->hasUrls
            ? Visibility::PUBLIC
            : Visibility::PRIVATE;

        return parent::addFileMetadataToConfig($config);
    }

    /**
     * @inheritDoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cloud/fsSettings', [
            'fs' => $this,
            'periods' => Assets::periodList(),
        ]);
    }

    protected function createBucketPrefix(): SegmentedPathInterface
    {
        // Note: ENVIRONMENT_ID may not be set when running cloud/build
        return HierarchicalPath::fromRelative(Config::create()->environmentId ?? '');
    }

    protected function createPath(string $path): SegmentedPathInterface
    {
        return HierarchicalPath::fromRelative(
            App::parseEnv($this->subpath) ?? '',
            $path,
        )->withoutEmptySegments();
    }

    public function createBucketPath(string $path): SegmentedPathInterface
    {
        return $this->createBucketPrefix()->append($this->createPath($path));
    }

    public function getBucketName(): ?string
    {
        return Plugin::getInstance()->getConfig()->projectId;
    }

    public function createCredentials(): ?Credentials
    {
        $key = Plugin::getInstance()->getConfig()->accessKey;

        return $key ? new Credentials(
            $key,
            Plugin::getInstance()->getConfig()->accessSecret,
            Plugin::getInstance()->getConfig()->accessToken,
        ) : null;
    }

    public function createClient(array $config = []): S3Client
    {
        $config = array_merge(
            [
                'region' => Plugin::getInstance()->getConfig()->getRegion(),
                'version' => 'latest',
                'http_handler' => new GuzzleHandler(Craft::createGuzzleClient()),
                'credentials' => $this->createCredentials(),
            ],
            Plugin::getInstance()->getConfig()->getS3ClientOptions(),
            $config
        );

        return new S3Client($config);
    }

    public function getClient(): S3Client
    {
        if (!isset($this->client)) {
            $this->client = $this->createClient();
        }

        return $this->client;
    }

    /**
     * @inheritDoc
     * All s3 objects are non-public
     */
    protected function visibility(): string
    {
        return Visibility::PRIVATE;
    }

    public function presignedUrl(string $command, string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        if ($this->useLocalFs) {
            throw new InvalidConfigException();
        }

        try {
            $commandConfig = $this->addFileMetadataToConfig($config);

            $command = $this->getClient()->getCommand($command, [
                'Bucket' => $this->getBucketName(),
                'Key' => $this->createBucketPath($path)->toString(),
            ] + $commandConfig);

            $request = $this->getClient()->createPresignedRequest(
                $command,
                $expiresAt,
            );

            return (string)$request->getUri();
        } catch (Throwable $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function copyFile(string $path, string $newPath, $config = []): void
    {
        if ($this->useLocalFs) {
            $this->getLocalFs()->copyFile($path, $newPath);
            return;
        }

        parent::copyFile(
            $path,
            $newPath,
            $this->addFileMetadataToConfig($config),
        );
    }

    /**
     * @inheritdoc
     */
    public function renameFile(string $path, string $newPath, $config = []): void
    {
        if ($this->useLocalFs) {
            $this->getLocalFs()->renameFile($path, $newPath);
            return;
        }

        parent::renameFile(
            $path,
            $newPath,
            $this->addFileMetadataToConfig($config),
        );
    }

    /**
     * @inheritdoc
     */
    public function createDirectory(string $path, array $config = []): void
    {
        if ($this->useLocalFs) {
            $this->getLocalFs()->createDirectory($path, $config);
            return;
        }

        parent::createDirectory(
            $path,
            $this->addFileMetadataToConfig($config),
        );
    }

    /**
     * @inheritDoc
     */
    public function getFileList(string $directory = '', bool $recursive = true): Generator
    {
        if ($this->useLocalFs) {
            return $this->getLocalFs()->getFileList($directory, $recursive);
        }

        return parent::getFileList($directory, $recursive);
    }

    /**
     * @inheritDoc
     */
    public function getFileSize(string $uri): int
    {
        if ($this->useLocalFs) {
            return $this->getLocalFs()->getFileSize($uri);
        }

        return parent::getFileSize($uri);
    }

    /**
     * @inheritDoc
     */
    public function getDateModified(string $uri): int
    {
        if ($this->useLocalFs) {
            return $this->getLocalFs()->getDateModified($uri);
        }

        return parent::getDateModified($uri);
    }


    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, array $config = []): void
    {
        if ($this->useLocalFs) {
            $this->getLocalFs()->write($path, $contents, $config);
            return;
        }

        $this->invalidateCdnPath($path);
        parent::write(
            $path,
            $contents,
            $this->addFileMetadataToConfig($config),
        );
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        if ($this->useLocalFs) {
            return $this->getLocalFs()->read($path);
        }

        return parent::read($path);
    }

    /**
     * @inheritDoc
     */
    public function writeFileFromStream(string $path, $stream, array $config = []): void
    {
        if ($this->useLocalFs) {
            $this->getLocalFs()->writeFileFromStream($path, $stream, $config);
            return;
        }

        $this->invalidateCdnPath($path);
        parent::writeFileFromStream(
            $path,
            $stream,
            $this->addFileMetadataToConfig($config),
        );
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        if ($this->useLocalFs) {
            return $this->getLocalFs()->fileExists($path);
        }

        return parent::fileExists($path);
    }

    /**
     * @inheritDoc
     */
    public function deleteFile(string $path): void
    {
        if ($this->useLocalFs) {
            $this->getLocalFs()->deleteFile($path);
            return;
        }

        parent::deleteFile($path);
    }

    /**
     * @inheritDoc
     */
    public function getFileStream(string $uriPath)
    {
        if ($this->useLocalFs) {
            return $this->getLocalFs()->getFileStream($uriPath);
        }

        return parent::getFileStream($uriPath);
    }

    /**
     * @inheritDoc
     */
    public function directoryExists(string $path): bool
    {
        if ($this->useLocalFs) {
            return $this->getLocalFs()->directoryExists($path);
        }

        return parent::directoryExists($path);
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        if ($this->useLocalFs) {
            $this->getLocalFs()->deleteDirectory($path);
            return;
        }

        parent::deleteDirectory($path);
    }

    public function replaceMetadata(string $path, array $config = []): void
    {
        if ($this->useLocalFs) {
            return;
        }

        try {
            $this->filesystem()->copy(
                $path,
                $path,
                $this->addFileMetadataToConfig($config),
            );
        } catch (FilesystemException|UnableToCopyFile $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * S3 encodes path segments when generating presigned PUT urls, so we need to do the same.
     */
    public static function urlEncodePathSegments(string $path): string
    {
        return Collection::make(explode('/', $path))
            ->map(fn($segment) => rawurlencode($segment))
            ->implode('/');
    }
}
