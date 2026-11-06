<?php

declare(strict_types=1);

namespace craft\cloud\signing;

use GuzzleHttp\Psr7\HttpFactory;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Exception\VerificationException;
use HttpMessageSignatures\Url\UrlSigner as HttpUrlSigner;
use HttpMessageSignatures\Url\UrlSigningConfig;
use HttpMessageSignatures\Url\UrlVerifier as HttpUrlVerifier;
use League\Uri\Components\Query;
use League\Uri\Exceptions\SyntaxError;
use League\Uri\Modifier;
use League\Uri\UriString;

/**
 * Signs URLs the Craft Cloud gateway verifies, using HTTP Message Signatures
 * over `@path` and `@query` with the signature in a query parameter.
 */
class UrlSigner
{
    private const array SIGNATURE_COMPONENTS = ['@path', '@query'];

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $signingKey,
        private readonly string $signatureParameter = 's',
    ) {}

    public function sign(string $url): string
    {
        return $this->createSigner()->sign($this->normalizeUrlForSigning($url));
    }

    public function verify(string $url): bool
    {
        try {
            $normalizedUrl = $this->normalizeUrlForSigning($url);
        } catch (SyntaxError) {
            return false;
        }

        if ($url !== $normalizedUrl) {
            return false;
        }

        try {
            return $this->createVerifier()->verify($url);
        } catch (VerificationException) {
            return false;
        }
    }

    /**
     * Normalizes query serialization before signing.
     *
     * Query strings can leave forward slashes unencoded (`template=_includes/head`)
     * or encode spaces as `+`. The signer returns a League URI-serialized URL, so
     * signing a normalized form keeps the signature tied to the exact URL we
     * return, while parsing with form-data semantics preserves the values PHP
     * exposes to controllers.
     *
     * @see https://github.com/craftcms/cms/pull/19057
     */
    private function normalizeUrlForSigning(string $url): string
    {
        $query = Query::fromFormData(UriString::parse($url)['query']);

        return Modifier::wrap($url)->withQuery($query->toRFC3986())->toString();
    }

    private function createSigner(): HttpUrlSigner
    {
        return new HttpUrlSigner(
            algorithm: new HmacSha256($this->signingKey),
            requestFactory: new HttpFactory(),
            config: $this->createConfig(),
        );
    }

    private function createVerifier(): HttpUrlVerifier
    {
        return new HttpUrlVerifier(
            algorithm: new HmacSha256($this->signingKey),
            requestFactory: new HttpFactory(),
            config: $this->createConfig(),
        );
    }

    private function createConfig(): UrlSigningConfig
    {
        return new UrlSigningConfig(components: self::SIGNATURE_COMPONENTS, signatureParam: $this->signatureParameter);
    }
}
