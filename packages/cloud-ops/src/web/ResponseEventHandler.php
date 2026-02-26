<?php

namespace craft\cloud\ops\web;

use Craft;
use craft\cloud\ops\fs\TmpFs;
use craft\cloud\ops\HeaderEnum;
use craft\cloud\Plugin;
use craft\helpers\StringHelper;
use craft\web\Response;
use Illuminate\Support\Collection;
use yii\base\Event;
use yii\web\Response as YiiResponse;
use yii\web\ServerErrorHttpException;

class ResponseEventHandler
{
    private Response $response;

    /**
     * @see https://developers.cloudflare.com/workers/platform/limits/#request-limits
     * @see https://docs.aws.amazon.com/elasticloadbalancing/latest/application/load-balancer-limits.html#http-headers-quotas
     * @see https://docs.aws.amazon.com/lambda/latest/dg/gettingstarted-limits.html
     */
    private const MAX_HEADER_LENGTH = 16 * 1024;

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
        if (Plugin::getInstance()->getConfig()->getDevMode()) {
            $this->addDevModeHeader();
        }

        $this->normalizeHeaders();

        if (Plugin::getInstance()->getConfig()->gzipResponse) {
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

    private function normalizeHeaders(): void
    {
        Collection::make($this->response->getHeaders())
            ->each(function(array $values, string $name) {
                if (HeaderEnum::SET_COOKIE->matches($name)) {
                    return;
                }

                $this->response->getHeaders()->set(
                    $name,
                    $this->joinHeaderValues($values),
                );
            });
    }

    private function joinHeaderValues(array $values): string
    {
        return Collection::make($values)
            ->filter()
            ->reduce(function($result, $value) {
                $newResult = $result === '' ? $value : $result . ',' . $value;

                if (StringHelper::byteLength($newResult) > self::MAX_HEADER_LENGTH) {
                    Craft::warning(
                        sprintf("Header value exceeds the maximum length of %s bytes; truncating response.", self::MAX_HEADER_LENGTH),
                        __METHOD__,
                    );

                    return $result;
                }

                return $newResult;
            }, '');
    }

    private function addDevModeHeader(): void
    {
        $this->response->getHeaders()->set(HeaderEnum::DEV_MODE->value, '1');
    }
}
