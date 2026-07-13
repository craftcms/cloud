<?php

namespace craft\cloud\web;

use Craft;
use craft\cloud\fs\TmpFs;
use craft\cloud\HeaderEnum;
use craft\cloud\Module;
use craft\web\Response;
use Illuminate\Support\Collection;
use yii\base\Event;
use yii\web\Response as YiiResponse;
use yii\web\ServerErrorHttpException;

class ResponseEventHandler
{
    private Response $response;

    public function __construct()
    {
        $this->response = Craft::$app->getResponse();
    }

    public function handle(): void
    {
        Event::on(
            Response::class,
            YiiResponse::EVENT_AFTER_PREPARE,
            fn(Event $event) => $this->afterPrepare($event),
        );
    }

    private function afterPrepare(Event $event): void
    {
        if (Module::getInstance()->getConfig()->getDevMode()) {
            $this->addDevModeHeader();
        }

        if (Module::getInstance()->getConfig()->gzipResponse) {
            $this->gzipResponse();
        }

        if (
            $this->response->stream &&
            !str_starts_with($this->response->getContentType(), 'text/')
        ) {
            $this->serveBinaryFromS3();
        }
    }

    private function gzipResponse(): void
    {
        $accepts = preg_split(
            '/\s*\,\s*/',
            Craft::$app->getRequest()->getHeaders()->get('Accept-Encoding') ?? ''
        );

        if (Collection::make($accepts)->doesntContain('gzip') || $this->response->content === null) {
            return;
        }

        $this->response->content = gzencode($this->response->content, 9);
        $this->response->getHeaders()->set('Content-Encoding', 'gzip');
    }

    /**
     * @throws ServerErrorHttpException
     */
    private function serveBinaryFromS3(): void
    {
        $stream = $this->response->stream[0] ?? null;

        if (!$stream) {
            throw new ServerErrorHttpException('Invalid stream in response.');
        }

        $path = uniqid('binary', true);

        /** @var TmpFs $fs */
        $fs = Craft::createObject([
            'class' => TmpFs::class,
        ]);

        // TODO: set expiry
        $fs->writeFileFromStream($path, $stream);

        // TODO: use \League\Flysystem\AwsS3V3\AwsS3V3Adapter::temporaryUrl?
        $cmd = $fs->getClient()->getCommand('GetObject', [
            'Bucket' => $fs->getBucketName(),
            'Key' => $fs->createBucketPath($path)->toString(),
            'ResponseContentDisposition' => $this->response->getHeaders()->get('content-disposition'),
        ]);

        // TODO: expiry config
        $s3Request = $fs->getClient()->createPresignedRequest($cmd, '+20 minutes');
        $url = (string) $s3Request->getUri();

        // Clear response so stream is reset and we don't recursively call this method.
        $this->response->clear();

        // Don't cache the redirect, as its validity is short-lived.
        $this->response->setNoCacheHeaders();

        $this->response->redirect($url);

        // Ensure we don't recursively call send()
        // @see https://github.com/craftcms/cms/pull/15014
        Craft::$app->end();
    }

    private function addDevModeHeader(): void
    {
        $this->response->getHeaders()->set(HeaderEnum::DEV_MODE->value, '1');
    }
}
