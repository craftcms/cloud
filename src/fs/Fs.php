<?php

namespace craft\cloud\fs;

use Aws\Credentials\Credentials;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use Aws\Signature\S3SignatureV4;
use Aws\Signature\SignatureProvider;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\cloud\Module;
use craft\cloud\StaticCache;
use craft\cloud\StaticCacheTag;
use craft\elements\Asset;
use craft\errors\FsException;
use craft\flysystem\base\FlysystemFs;
use craft\fs\Local;
use craft\helpers\App;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;
use craft\helpers\Image;
use DateTime;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Collection;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\Visibility;
use League\Flysystem\WhitespacePathNormalizer;
use League\Uri\Components\HierarchicalPath;
use League\Uri\Contracts\SegmentedPathInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Modifier;
use Psr\Http\Message\RequestInterface;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * @property-read ?string $settingsHtml
 */
abstract class Fs extends FlysystemFs implements TemporaryUploadUrlInterface
{
    // Most image headers are much smaller, but large EXIF/XMP/ICC blocks can
    // push JPEG dimensions farther in. Stay bounded to avoid full downloads.
    private const IMAGE_DIMENSION_INITIAL_BYTES = 1024 * 1024;
    private const IMAGE_DIMENSION_MAX_BYTES = 8 * 1024 * 1024;

    protected static bool $showUrlSetting = false;
    protected ?string $expires = null;
    protected ?Local $localFs = null;
    protected S3Client $client;
    private ?S3Client $uploadClient = null;
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
            return Modifier::wrap($this->getLocalFs()->getRootUrl() ?? '/')
                ->appendPath($this->createPath($path))
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
            $environmentId = Module::getInstance()->getConfig()->environmentId;
            $objectKey = $this->createBucketPath($path)->toString();
            $cdnTag = StaticCacheTag::create("$environmentId:cdn:$objectKey");
            $tags = [$cdnTag];

            if ($this instanceof AssetsFs && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['pdf', 'svg'], true)) {
                $rasterizeTag = StaticCacheTag::create("$environmentId:rasterize:$objectKey");
                $tags[] = strlen($rasterizeTag->getValue()) > StaticCache::MAX_TAG_VALUE_LENGTH
                    ? "$environmentId:rasterize"
                    : $rasterizeTag;
            }

            Module::getInstance()->getStaticCache()->addPurgeTags($tags);

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

    public function presignedUrl(string $command, string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        return (string)$this->presignRequest($command, $this->createBucketPath($path)->toString(), $expiresAt, $config)->getUri();
    }

    /**
     * @inheritdoc
     */
    public function temporaryUploadUrl(string $path, DateTimeInterface $expiresAt, array $options = []): ?array
    {
        if ($this->useLocalFs) {
            return null;
        }

        // The type is signed, so default to the one storage would serve for the path rather than the SDK's octet-stream
        $config = array_intersect_key($options, ['ContentType' => true, 'ContentLength' => true]);
        $config['ContentType'] ??= FileHelper::getMimeTypeByExtension($path) ?? 'application/octet-stream';

        // Name the object the way Flysystem does, unencoded, so Craft finds the file at the same path afterward
        $key = (new PathPrefixer($this->createBucketPath('')->toString()))
            ->prefixPath((new WhitespacePathNormalizer())->normalizePath($path));

        try {
            $request = $this->presignRequest('PutObject', $key, $expiresAt, $config, $this->getUploadClient());
        } catch (FsException $exception) {
            // Craft falls back to receiving the file itself
            Module::warning("Unable to create a temporary upload URL for \"{$path}\": {$exception->getMessage()}");
            return null;
        }

        parse_str($request->getUri()->getQuery(), $query);
        $headers = [];

        // The signature covers these headers, so the client must send them
        foreach (explode(';', $query['X-Amz-SignedHeaders'] ?? '') as $name) {
            if ($name !== '' && $name !== 'host') {
                $headers[$name] = $request->getHeaderLine($name);
            }
        }

        return [
            'url' => (string)$request->getUri(),
            'headers' => $headers,
        ];
    }

    /**
     * Returns a client whose presigned requests also sign `Content-Type` and `Content-Length`, which the SDK leaves
     * unsigned, so storage rejects uploads of another type or size.
     */
    private function getUploadClient(): S3Client
    {
        return $this->uploadClient ??= $this->createClient([
            'signature_provider' => fn(string $version, string $service, string $region) => in_array($version, ['v4', 's3v4'], true)
                ? new class($service, $region) extends S3SignatureV4 {
                    protected function getHeaderBlacklist(): array
                    {
                        return array_diff_key(parent::getHeaderBlacklist(), ['content-type' => true, 'content-length' => true]);
                    }
                }
                : SignatureProvider::defaultProvider()($version, $service, $region),
        ]);
    }

    private function presignRequest(string $command, string $key, DateTimeInterface $expiresAt, array $config = [], ?S3Client $client = null): RequestInterface
    {
        if ($this->useLocalFs) {
            throw new InvalidConfigException();
        }

        try {
            $commandConfig = $this->addFileMetadataToConfig($config);

            $client ??= $this->getClient();
            $command = $client->getCommand($command, [
                'Bucket' => $this->getBucketName(),
                'Key' => $key,
            ] + $commandConfig);

            return $client->createPresignedRequest(
                $command,
                $expiresAt,
            );
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
        if ($this->useLocalFs) {
            $this->getLocalFs()->renameFile($path, $newPath);
            return;
        }

        try {
            $this->filesystem()->move(
                $path,
                $newPath,
                $this->addFileMetadataToConfig($config),
            );
        } catch (FilesystemException|UnableToMoveFile $exception) {
            throw new FsException($exception->getMessage(), 0, $exception);
        }

        $this->invalidateCdnPath($path);
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

    public function getImageDimensions(string $uriPath): ?array
    {
        if (Assets::getFileKindByExtension($uriPath) !== Asset::KIND_IMAGE) {
            return null;
        }

        for ($bytes = self::IMAGE_DIMENSION_INITIAL_BYTES; $bytes <= self::IMAGE_DIMENSION_MAX_BYTES; $bytes *= 2) {
            $dimensions = $this->getImageDimensionsFromRange($uriPath, $bytes);

            if ($dimensions !== null) {
                return $dimensions;
            }
        }

        Module::warning(
            "Unable to determine image dimensions for \"{$uriPath}\" within the " . self::IMAGE_DIMENSION_MAX_BYTES . ' byte read limit.',
        );

        return null;
    }

    private function getImageDimensionsFromRange(string $uriPath, int $bytes): ?array
    {
        $stream = $this->getFileStreamRange($uriPath, 0, $bytes - 1);

        return $this->getImageDimensionsFromStream($stream);
    }

    private function getImageDimensionsFromStream($stream): ?array
    {
        if (!is_resource($stream)) {
            return null;
        }

        try {
            $imageSize = Image::imageSizeByStream($stream);

            if ($imageSize === false || !isset($imageSize[0], $imageSize[1])) {
                return null;
            }

            return [
                (int)$imageSize[0] ?: null,
                (int)$imageSize[1] ?: null,
            ];
        } catch (Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return resource|null
     */
    public function getFileStreamRange(string $uriPath, int $start, int $end)
    {
        if ($start < 0 || $end < $start) {
            return null;
        }

        $sourceStream = null;

        try {
            if (!$this->useLocalFs) {
                $bucket = $this->getBucketName();

                if ($bucket === null) {
                    return null;
                }

                $object = $this->getClient()->getObject([
                    'Bucket' => $bucket,
                    'Key' => $this->createBucketPath($uriPath)->toString(),
                    'Range' => "bytes=$start-$end",
                ]);

                return $this->stringStream((string)$object->get('Body'));
            }

            $sourceStream = $this->getFileStream($uriPath);

            if (fseek($sourceStream, $start) === -1) {
                return null;
            }

            $data = stream_get_contents($sourceStream, $end - $start + 1);

            return is_string($data) ? $this->stringStream($data) : null;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
        }
    }

    /**
     * @return resource|null
     */
    private function stringStream(string $contents)
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
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
