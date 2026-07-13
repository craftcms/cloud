<?php

namespace craft\cloud\signing;

use craft\cloud\Module;
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
        return $this->createSigner()->sign($this->normalizeUrlForSigning($url));
    }

    public function verify(string $url): bool
    {
        try {
            $normalizedUrl = $this->normalizeUrlForSigning($url);
        } catch (SyntaxError $e) {
            Module::warning('Malformed signed URL', [
                'reason' => $e->getMessage(),
                'url' => $url,
                'signatureParameter' => $this->signatureParameter,
            ]);

            return false;
        }

        try {
            if ($url !== $normalizedUrl) {
                throw new VerificationException('URL is not normalized.');
            }

            return $this->createVerifier()->verify($url);
        } catch (VerificationException $e) {
            Module::info('Invalid URL signature', [
                'reason' => $e->getMessage(),
                'url' => $url,
                'signatureParameter' => $this->signatureParameter,
            ]);

            return false;
        }
    }

    /**
     * Normalize query serialization before signing.
     *
     * Older Craft versions leave forward slashes unencoded in query values,
     * e.g. `template=_includes/head`, and Yii query generation may encode
     * spaces as `+`. The shared URL signer signs `@query`, then returns a
     * League URI-serialized URL where slashes are encoded. Signing a normalized
     * form keeps the signature tied to the exact URL we return, while parsing
     * with form-data semantics preserves the values PHP will expose to action
     * controllers.
     *
     * @see https://github.com/craftcms/cms/pull/19057
     */
    private function normalizeUrlForSigning(string $url): string
    {
        $query = Query::fromFormData(UriString::parse($url)['query']);

        return Modifier::wrap($url)
            ->withQuery($query->toRFC3986())
            ->toString();
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
