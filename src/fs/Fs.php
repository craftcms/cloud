<?php

namespace craft\cloud\fs;

use Aws\Credentials\Credentials;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use Craft;
use craft\base\Fs as BaseFs;
use craft\cloud\Module;
use craft\cloud\StaticCache;
use craft\cloud\StaticCacheTag;
use craft\errors\FsException;
use craft\helpers\App;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\models\FsListing;
use DateTime;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Collection;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
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
abstract class Fs extends BaseFs
{
    protected static bool $showUrlSetting = false;
    protected ?string $expires = null;
    protected S3Client $client;
    protected Flysystem $filesystem;
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
            return Modifier::wrap($this->getLocalRootUrl())
                ->appendSegment($path)
                ->unwrap();
        }

        $baseUrl = App::parseEnv($this->baseUrl);

        if ($baseUrl) {
            return Modifier::wrap($baseUrl)
                ->appendSegment($this->createPath($path))
                ->unwrap();
        }

        return Modifier::wrap(Module::getInstance()->getConfig()->cdnBaseUrl)
            ->appendSegment($this->createBucketPath($path))
            ->unwrap();
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'localFsPath' => Craft::t('app', 'Base Path'),
            'localFsUrl' => Craft::t('app', 'Base URL'),
        ]);
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
    protected function createAdapter(): FilesystemAdapter
    {
        if ($this->useLocalFs) {
            return new LocalFilesystemAdapter($this->getLocalRootPath());
        }

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
            $prefix = StaticCache::CDN_PREFIX . Module::getInstance()->getConfig()->environmentId . ':';
            $tag = StaticCacheTag::create($this->createBucketPath($path)->toString())
                ->minify(false)
                ->withPrefix($prefix);

            Module::getInstance()->getStaticCache()->purgeTags($tag);

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
        if ($this->useLocalFs) {
            return $config;
        }

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

        $config['visibility'] ??= $this->visibility();

        return $config;
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
        return HierarchicalPath::fromRelative(Module::getInstance()->getConfig()->environmentId ?? '');
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

    protected function getLocalRootPath(): string
    {
        $basePath = Craft::getAlias(App::parseEnv($this->localFsPath) ?? $this->localFsPath ?? '');
        $subpath = $this->createPath('')->toString();

        return rtrim($basePath, '/') . ($subpath !== '' ? "/$subpath" : '');
    }

    protected function getLocalRootUrl(): string
    {
        $baseUrl = App::parseEnv($this->localFsUrl) ?? $this->localFsUrl ?? '/';
        $subpath = $this->createPath('')->toString();

        return rtrim($baseUrl, '/') . ($subpath !== '' ? "/$subpath" : '');
    }

    public function getBucketName(): ?string
    {
        return Module::getInstance()->getConfig()->projectId;
    }

    public function createCredentials(): ?Credentials
    {
        $key = Module::getInstance()->getConfig()->accessKey;

        return $key ? new Credentials(
            $key,
            Module::getInstance()->getConfig()->accessSecret,
            Module::getInstance()->getConfig()->accessToken,
        ) : null;
    }

    public function createClient(array $config = []): S3Client
    {
        $config = array_merge(
            [
                'region' => Module::getInstance()->getConfig()->getRegion(),
                'version' => 'latest',
                'http_handler' => new GuzzleHandler(Craft::createGuzzleClient()),
                'credentials' => $this->createCredentials(),
            ],
            Module::getInstance()->getConfig()->getS3ClientOptions(),
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

    protected function filesystem(): Flysystem
    {
        if (!isset($this->filesystem)) {
            $this->filesystem = new Flysystem($this->createAdapter());
        }

        return $this->filesystem;
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
        try {
            $this->filesystem()->copy(
                $path,
                $newPath,
                $this->addFileMetadataToConfig($config),
            );
        } catch (FilesystemException|UnableToCopyFile $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritdoc
     */
    public function renameFile(string $path, string $newPath, $config = []): void
    {
        try {
            $this->filesystem()->move(
                $path,
                $newPath,
                $this->addFileMetadataToConfig($config),
            );
        } catch (FilesystemException|UnableToMoveFile $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }

        if (!$this->useLocalFs) {
            $this->invalidateCdnPath($path);
        }
    }

    /**
     * @inheritdoc
     */
    public function createDirectory(string $path, array $config = []): void
    {
        try {
            $this->filesystem()->createDirectory($path, $this->addFileMetadataToConfig($config));
        } catch (FilesystemException $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritDoc
     */
    public function getFileList(string $directory = '', bool $recursive = true): Generator
    {
        foreach ($this->filesystem()->listContents(trim($directory, '/'), $recursive) as $item) {
            if (!$item instanceof StorageAttributes) {
                continue;
            }

            $uri = trim($item->path(), '/');

            if ($uri === '') {
                continue;
            }

            $dirname = pathinfo($uri, PATHINFO_DIRNAME);

            yield new FsListing([
                'dirname' => $dirname === '.' ? '' : $dirname,
                'basename' => pathinfo($uri, PATHINFO_BASENAME),
                'type' => $item->isDir() ? 'dir' : 'file',
                'dateModified' => $item->lastModified(),
                'fileSize' => $item->isFile() && method_exists($item, 'fileSize') ? $item->fileSize() : null,
            ]);
        }
    }

    /**
     * @inheritDoc
     */
    public function getFileSize(string $uri): int
    {
        try {
            return $this->filesystem()->fileSize($uri);
        } catch (FilesystemException $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritDoc
     */
    public function getDateModified(string $uri): int
    {
        try {
            return $this->filesystem()->lastModified($uri);
        } catch (FilesystemException $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }


    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, array $config = []): void
    {
        if (!$this->useLocalFs) {
            $this->invalidateCdnPath($path);
        }

        try {
            $this->filesystem()->write($path, $contents, $this->addFileMetadataToConfig($config));
        } catch (FilesystemException $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        try {
            return $this->filesystem()->read($path);
        } catch (FilesystemException $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritDoc
     */
    public function writeFileFromStream(string $path, $stream, array $config = []): void
    {
        if (!$this->useLocalFs) {
            $this->invalidateCdnPath($path);
        }

        try {
            $this->filesystem()->writeStream($path, $stream, $this->addFileMetadataToConfig($config));
        } catch (FilesystemException $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        return $this->filesystem()->fileExists($path);
    }

    /**
     * @inheritDoc
     */
    public function deleteFile(string $path): void
    {
        try {
            $this->filesystem()->delete($path);
        } catch (FilesystemException $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @inheritDoc
     */
    public function getFileStream(string $uriPath)
    {
        try {
            $stream = $this->filesystem()->readStream($uriPath);
        } catch (FilesystemException $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }

        if (!is_resource($stream)) {
            throw new FsException("Unable to open $uriPath.");
        }

        return $stream;
    }

    /**
     * @inheritDoc
     */
    public function directoryExists(string $path): bool
    {
        return $this->filesystem()->directoryExists(trim($path, '/'));
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        try {
            $this->filesystem()->deleteDirectory(trim($path, '/'));
        } catch (FilesystemException $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }
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

    public function renameDirectory(string $path, string $newName): void
    {
        $sourcePath = trim($path, '/');

        if ($sourcePath === '' || !$this->directoryExists($sourcePath)) {
            throw new FsException("No folder exists at path: $path");
        }

        $newName = trim($newName, '/');

        if ($newName === '') {
            throw new FsException('New directory name cannot be empty.');
        }

        $parentPath = pathinfo($sourcePath, PATHINFO_DIRNAME);
        $targetPath = $parentPath === '.' ? $newName : "$parentPath/$newName";

        if ($targetPath === $sourcePath) {
            return;
        }

        $this->createDirectory($targetPath);

        foreach ($this->filesystem()->listContents($sourcePath, true) as $item) {
            if (!$item instanceof StorageAttributes || !$item->isFile()) {
                continue;
            }

            $file = trim($item->path(), '/');
            $this->renameFile($file, $this->swapPathPrefix($file, $sourcePath, $targetPath));
        }

        $this->deleteDirectory($sourcePath);
    }

    private function swapPathPrefix(string $path, string $sourcePath, string $targetPath): string
    {
        return preg_replace(
            '/^' . preg_quote($sourcePath, '/') . '(?=\/|$)/',
            $targetPath,
            trim($path, '/'),
            1,
        ) ?? trim($path, '/');
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
