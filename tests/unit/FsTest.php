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

    public function testDirectUploadUrlSignsPutWithHeadersTheClientMustSend(): void
    {
        $upload = Craft::createObject(TmpFs::class)->getDirectUploadUrl('mcp-uploads/abc', new DateTimeImmutable('+15 minutes'));
        $url = parse_url($upload['url']);
        parse_str($url['query'], $query);

        $this->assertSame('PUT', $upload['method']);
        $this->assertSame('/environment-id/storage/tmp/mcp-uploads/abc', $url['path']);
        $this->assertSame('host;x-amz-meta-visibility', $query['X-Amz-SignedHeaders']);
        $this->assertSame(['x-amz-meta-visibility' => 'private'], $upload['headers']);
    }

    public function testDirectUploadUrlIncludesEverySignedMetadataHeader(): void
    {
        $fs = Craft::createObject(['class' => AssetsFs::class, 'hasUrls' => true]);
        $fs->useLocalFs = false;
        $upload = $fs->getDirectUploadUrl('photo.jpg', new DateTimeImmutable('+15 minutes'));

        $this->assertSame(['x-amz-meta-max-age', 'x-amz-meta-visibility'], array_keys($upload['headers']));
        $this->assertSame('public', $upload['headers']['x-amz-meta-visibility']);
    }

    public function testDirectUploadUrlIsUnavailableForLocalFilesystems(): void
    {
        $fs = Craft::createObject(TmpFs::class);
        $fs->useLocalFs = true;

        $this->assertNull($fs->getDirectUploadUrl('mcp-uploads/abc', new DateTimeImmutable('+15 minutes')));
    }
}
