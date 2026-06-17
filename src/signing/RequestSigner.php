<?php

namespace craft\cloud\signing;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Signer;
use Psr\Http\Message\RequestInterface;
use yii\base\Exception;

class RequestSigner
{
    private const DEFAULT_SIGNATURE_COMPONENTS = ['@method', '@target-uri'];
    private const DEFAULT_EXPIRES_AFTER = 300;
    private const DEFAULT_SIGNATURE_ID = 'sig';
    private const DEFAULT_KEY_ID = 'hmac';

    public function __construct(
        private readonly string $signingKey,
        private readonly array $components = self::DEFAULT_SIGNATURE_COMPONENTS,
        private readonly int $expiresAfter = self::DEFAULT_EXPIRES_AFTER,
        private readonly string $signatureId = self::DEFAULT_SIGNATURE_ID,
        private readonly string $keyId = self::DEFAULT_KEY_ID,
    ) {
    }

    public function sign(RequestInterface $request): RequestInterface
    {
        $created = time();
        $signedRequest = $this->createSigner()->sign(
            $request,
            $this->components,
            [
                'signatureId' => $this->signatureId,
                'keyid' => $this->keyId,
                'created' => $created,
                'expires' => $created + $this->expiresAfter,
            ],
        );

        if (!$signedRequest instanceof RequestInterface) {
            throw new Exception('Request signatures must produce a signed request.');
        }

        return $signedRequest;
    }

    /** @internal */
    public function createHandlerStack(?HandlerStack $handlerStack = null): HandlerStack
    {
        $handlerStack ??= HandlerStack::create();
        $handlerStack->push($this->createMiddleware(), 'craft_cloud_request_signing');

        return $handlerStack;
    }

    private function createMiddleware(): callable
    {
        return Middleware::mapRequest(
            fn(RequestInterface $request): RequestInterface => $this->sign($request),
        );
    }

    private function createSigner(): Signer
    {
        return new Signer(new HmacSha256($this->signingKey));
    }
}
