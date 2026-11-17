<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\cloud\fs\AssetsFs;
use craft\cloud\fs\TmpFs;
use craft\cloud\Module;
use DateTimeImmutable;

class FsTest extends Unit
{
    private ?Module $previousModule = null;

    protected function _before(): void
    {
        parent::_before();
        $this->previousModule = Module::getInstance();
        $module = new Module('cloud');
        Module::setInstance($module);
        $module->getConfig()
            ->projectId('project-id')
            ->environmentId('environment-id')
            ->accessKey('access-key')
            ->accessSecret('access-secret')
            ->region('us-west-2');
    }

    protected function _after(): void
    {
        Module::setInstance($this->previousModule);
        parent::_after();
    }

    public function testTemporaryUploadUrlSignsTheFileTypeAndSize(): void
    {
        $upload = Craft::createObject(TmpFs::class)->temporaryUploadUrl('_upload-sessions/abc.jpg', new DateTimeImmutable('+15 minutes'), [
            'ContentType' => 'image/jpeg',
            'ContentLength' => 7340032,
        ]);
        $url = parse_url($upload['url']);
        parse_str($url['query'], $query);

        $this->assertSame('/environment-id/storage/tmp/_upload-sessions/abc.jpg', $url['path']);
        // The SDK leaves these two unsigned by default, which would let a client store any type or size
        $this->assertSame('content-length;content-type;host;x-amz-meta-visibility', $query['X-Amz-SignedHeaders']);
        $this->assertSame([
            'content-length' => '7340032',
            'content-type' => 'image/jpeg',
            'x-amz-meta-visibility' => 'private',
        ], $upload['headers']);
    }

    public function testTemporaryUploadUrlIncludesEverySignedMetadataHeader(): void
    {
        $fs = Craft::createObject(['class' => AssetsFs::class, 'hasUrls' => true]);
        $fs->useLocalFs = false;
        $upload = $fs->temporaryUploadUrl('photo.jpg', new DateTimeImmutable('+15 minutes'));

        $this->assertSame(['content-type', 'x-amz-meta-max-age', 'x-amz-meta-visibility'], array_keys($upload['headers']));
        $this->assertSame('public', $upload['headers']['x-amz-meta-visibility']);
        // Without a type from the caller, the path's is signed, so the CDN serves it correctly
        $this->assertSame('image/jpeg', $upload['headers']['content-type']);
    }

    public function testOtherPresignedUrlsStillLeaveTheFileTypeUnsigned(): void
    {
        $url = Craft::createObject(TmpFs::class)->presignedUrl('PutObject', 'abc.jpg', new DateTimeImmutable('+15 minutes'), ['ContentType' => 'image/jpeg']);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('host;x-amz-meta-visibility', $query['X-Amz-SignedHeaders']);
    }

    public function testTemporaryUploadUrlIsUnavailableForLocalFilesystems(): void
    {
        $fs = Craft::createObject(TmpFs::class);
        $fs->useLocalFs = true;

        $this->assertNull($fs->temporaryUploadUrl('_upload-sessions/abc', new DateTimeImmutable('+15 minutes')));
    }
}
