<?php

declare(strict_types=1);

namespace craft\cloud\signing;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Signer;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Signs outgoing HTTP requests for Craft Cloud with HTTP Message Signatures.
 */
class RequestSigner
{
    private const array DEFAULT_SIGNATURE_COMPONENTS = ['@method', '@target-uri'];
    private const int DEFAULT_EXPIRES_AFTER = 300;
    private const string DEFAULT_SIGNATURE_ID = 'sig';
    private const string DEFAULT_KEY_ID = 'hmac';

    /**
     * @param list<string> $components
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $signingKey,
        private readonly array $components = self::DEFAULT_SIGNATURE_COMPONENTS,
        private readonly int $expiresAfter = self::DEFAULT_EXPIRES_AFTER,
        private readonly string $signatureId = self::DEFAULT_SIGNATURE_ID,
        private readonly string $keyId = self::DEFAULT_KEY_ID,
    ) {}

    public function sign(RequestInterface $request): RequestInterface
    {
        $created = time();
        $signedRequest = new Signer(new HmacSha256($this->signingKey))->sign($request, $this->components, [
            'signatureId' => $this->signatureId,
            'keyid' => $this->keyId,
            'created' => $created,
            'expires' => $created + $this->expiresAfter,
            'tag' => 'craft-cloud',
        ]);

        if (!$signedRequest instanceof RequestInterface) {
            throw new RuntimeException('Request signatures must produce a signed request.');
        }

        return $signedRequest;
    }

    public function createHandlerStack(?HandlerStack $handlerStack = null): HandlerStack
    {
        $handlerStack ??= HandlerStack::create();
        $handlerStack->push(Middleware::mapRequest($this->sign(...)), 'craft_cloud_request_signing');

        return $handlerStack;
    }
}
