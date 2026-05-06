<?php

namespace craft\cloud;

use Craft;
use craft\cloud\fs\BuildArtifactsFs;
use craft\helpers\App;
use GuzzleHttp\Psr7\Request;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Signer;
use Illuminate\Support\Collection;
use Psr\Http\Message\RequestInterface;
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

        if (Module::getInstance()->getConfig()->getDevMode()) {
            $headers->put(HeaderEnum::DEV_MODE->value, '1');
        }

        $url = Craft::$app->getRequest()->getIsConsoleRequest()
            ? Module::getInstance()->getConfig()->getPreviewDomainUrl()
            : Craft::$app->getRequest()->getHostInfo();

        if (!$url) {
            throw new Exception('Gateway API requests require a URL.');
        }

        $signer = Helper::createSigner();
        $request = new Request(
            'HEAD',
            (string) $url,
            $headers->all(),
        );

        $signedRequest = $signer->sign(
            $request,
            $headers->keys()->all(),
            ['keyid' => 'hmac'],
        );

        if (!$signedRequest instanceof RequestInterface) {
            throw new Exception('Signed Gateway API request must be a PSR-7 request.');
        }

        return Craft::createGuzzleClient()->send($signedRequest);
    }

    private static function createSigner(): Signer
    {
        return new Signer(new HmacSha256(Module::getInstance()->getConfig()->signingKey));
    }
}
