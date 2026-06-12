<?php

namespace craft\cloud\signing;

use Craft;
use GuzzleHttp\Psr7\HttpFactory;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Exception\VerificationException;
use HttpMessageSignatures\Url\UrlSigner as HttpUrlSigner;
use HttpMessageSignatures\Url\UrlSigningConfig;
use HttpMessageSignatures\Url\UrlVerifier as HttpUrlVerifier;

class UrlSigner
{
    private const SIGNATURE_COMPONENTS = ['@path', '@query'];

    public function __construct(
        private readonly string $signingKey,
        private readonly string $signatureParameter = 's',
    ) {
    }

    public function sign(string $url): string
    {
        return $this->createSigner()->sign($url);
    }

    public function verify(string $url): bool
    {
        try {
            return $this->createVerifier()->verify($url);
        } catch (VerificationException $e) {
            Craft::info([
                'message' => 'Invalid URL signature',
                'reason' => $e->getMessage(),
                'url' => $url,
                'signatureParameter' => $this->signatureParameter,
            ], __METHOD__);

            return false;
        }
    }

    private function createSigner(): HttpUrlSigner
    {
        return new HttpUrlSigner(
            algorithm: new HmacSha256($this->signingKey),
            requestFactory: new HttpFactory(),
            config: new UrlSigningConfig(
                components: self::SIGNATURE_COMPONENTS,
                signatureParam: $this->signatureParameter,
            ),
        );
    }

    private function createVerifier(): HttpUrlVerifier
    {
        return new HttpUrlVerifier(
            algorithm: new HmacSha256($this->signingKey),
            requestFactory: new HttpFactory(),
            config: new UrlSigningConfig(
                components: self::SIGNATURE_COMPONENTS,
                signatureParam: $this->signatureParameter,
            ),
        );
    }
}
