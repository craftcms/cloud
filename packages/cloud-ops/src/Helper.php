<?php

namespace craft\cloud;

use Craft;
use craft\cloud\fs\BuildArtifactsFs;
use craft\helpers\App;
use GuzzleHttp\Psr7\Request;
use HttpSignatures\Context;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use yii\base\Exception;

class Helper
{
    public static function isCraftCloud(): bool
    {
        return App::env('CRAFT_CLOUD') ?? App::env('AWS_LAMBDA_RUNTIME_API') ?? false;
    }

    public static function artifactUrl(string $path = ''): string
    {
        return (new BuildArtifactsFs())->createUrl($path);
    }

    public static function makeGatewayApiRequest(iterable $headers): ResponseInterface
    {
        if (!Helper::isCraftCloud()) {
            throw new Exception('Gateway API requests are only supported in a Craft Cloud environment.');
        }

        $headers = Collection::make($headers)
            ->put(HeaderEnum::REQUEST_TYPE->value, 'api');

        if (Plugin::getInstance()->getConfig()->getDevMode()) {
            $headers->put(HeaderEnum::DEV_MODE->value, '1');
        }

        $url = Craft::$app->getRequest()->getIsConsoleRequest()
            ? Plugin::getInstance()->getConfig()->getPreviewDomainUrl()
            : Craft::$app->getRequest()->getHostInfo();

        if (!$url) {
            throw new Exception('Gateway API requests require a URL.');
        }

        $context = Helper::createSigningContext($headers->keys());
        $request = new Request(
            'HEAD',
            (string) $url,
            $headers->all(),
        );

        return Craft::createGuzzleClient()->send(
            $context->signer()->sign($request)
        );
    }

    private static function createSigningContext(iterable $headers = []): Context
    {
        $headers = Collection::make($headers);

        return new Context([
            'keys' => [
                'hmac' => Plugin::getInstance()->getConfig()->signingKey,
            ],
            'algorithm' => 'hmac-sha256',
            'headers' => $headers->all(),
        ]);
    }
}
