<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\signing\UrlSigner;
use League\Uri\Components\Query;

class UrlSignerTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    private UrlSigner $urlSigner;

    protected function _before()
    {
        $this->urlSigner = new UrlSigner('test-signing-key');
    }

    public function testSignAddsSignatureParameter()
    {
        $url = 'https://example.com/test';
        $signedUrl = $this->urlSigner->sign($url);

        $this->tester->assertStringContainsString('?s=', $signedUrl);
        $this->tester->assertStringStartsWith($url, $signedUrl);
    }

    public function testSignedUrlContainsValidSignature()
    {
        $url = 'https://example.com/test';
        $signedUrl = $this->urlSigner->sign($url);

        $this->tester->assertFalse($this->urlSigner->verify($url));
        $this->tester->assertTrue($this->urlSigner->verify($signedUrl));
    }

    public function testVerifyReturnsFalseForTamperedUrl()
    {
        $url = 'https://example.com/test';
        $signedUrl = $this->urlSigner->sign($url);
        $tamperedUrl = $signedUrl . '&tamper=true';

        $this->tester->assertFalse($this->urlSigner->verify($tamperedUrl));
    }

    public function testVerifyReturnsFalseForWrongSignature()
    {
        $url = 'https://example.com/test?s=wrongsignature';

        $this->tester->assertFalse($this->urlSigner->verify($url));
    }

    public function testVerifyReturnsFalseForMalformedUrl(): void
    {
        $url = 'https://exa mple.com/test?s=wrongsignature';

        $this->tester->assertFalse($this->urlSigner->verify($url));
    }

    public function testSignWithExistingQueryParameters()
    {
        $url = 'https://example.com/test?foo=bar&baz=qux';
        $signedUrl = $this->urlSigner->sign($url);

        $this->tester->assertStringContainsString('foo=bar', $signedUrl);
        $this->tester->assertStringContainsString('baz=qux', $signedUrl);
        $this->tester->assertStringContainsString('&s=', $signedUrl);
        $this->tester->assertTrue($this->urlSigner->verify($signedUrl));
    }

    public function testSignNormalizesQueryValuesBeforeSigning(): void
    {
        $url = 'https://example.com/actions/cloud/esi/render-template?template=_includes/head';
        $signedUrl = $this->urlSigner->sign($url);

        $this->tester->assertStringContainsString('template=_includes%2Fhead', $signedUrl);
        $this->tester->assertTrue($this->urlSigner->verify($signedUrl));
    }

    public function testSignPreservesFormEncodedQueryValues(): void
    {
        $url = 'https://example.com/actions/cloud/esi/render-template?variables%5Btitle%5D=Hello+world';
        $signedUrl = $this->urlSigner->sign($url);

        $this->tester->assertStringContainsString('variables%5Btitle%5D=Hello%20world', $signedUrl);
        $this->tester->assertStringNotContainsString('variables%5Btitle%5D=Hello%2Bworld', $signedUrl);
        $this->tester->assertTrue($this->urlSigner->verify($signedUrl));
    }

    public function testVerifyRejectsNonCanonicalQueryValues(): void
    {
        $encodedUrl = 'https://example.com/actions/cloud/esi/render-template?template=_includes%2Fhead';
        $signedUrl = $this->urlSigner->sign($encodedUrl);
        $rawUrl = str_replace('template=_includes%2Fhead', 'template=_includes/head', $signedUrl);

        $this->tester->assertFalse($this->urlSigner->verify($rawUrl));
    }

    public function testVerifyRejectsQueryValuesThatParseDifferently(): void
    {
        $encodedUrl = 'https://example.com/actions/cloud/esi/render-template?template=foo%2Bbar';
        $signedUrl = $this->urlSigner->sign($encodedUrl);
        $rawUrl = str_replace('template=foo%2Bbar', 'template=foo+bar', $signedUrl);

        $this->tester->assertFalse($this->urlSigner->verify($rawUrl));
    }

    public function testVerifyRejectsAlternateFormEncodedQueryValues(): void
    {
        $encodedUrl = 'https://example.com/actions/cloud/esi/render-template?variables%5Btitle%5D=Hello%20world';
        $signedUrl = $this->urlSigner->sign($encodedUrl);
        $rawUrl = str_replace('variables%5Btitle%5D=Hello%20world', 'variables%5Btitle%5D=Hello+world', $signedUrl);

        $this->tester->assertFalse($this->urlSigner->verify($rawUrl));
    }

    public function testSignReplacesExistingSignatureParameter()
    {
        $signedUrl = $this->urlSigner->sign('https://example.com/test?s=old&foo=bar');

        $parameters = Query::fromUri($signedUrl)->parameters();

        $this->tester->assertArrayHasKey('s', $parameters);
        $this->tester->assertNotSame('old', $parameters['s']);
        $this->tester->assertTrue($this->urlSigner->verify($signedUrl));
    }

    public function testCustomSignatureParameter()
    {
        $customSigner = new UrlSigner('test-key', 'signature');
        $url = 'https://example.com/test';
        $signedUrl = $customSigner->sign($url);

        $this->tester->assertStringContainsString('signature=', $signedUrl);
        $this->tester->assertTrue($customSigner->verify($signedUrl));
    }

    public function testDifferentKeysProduceDifferentSignatures()
    {
        $signer1 = new UrlSigner('key1');
        $signer2 = new UrlSigner('key2');

        $url = 'https://example.com/test';
        $signed1 = $signer1->sign($url);
        $signed2 = $signer2->sign($url);

        $this->tester->assertNotEquals($signed1, $signed2);

        // Each signer should only verify its own signature
        $this->tester->assertTrue($signer1->verify($signed1));
        $this->tester->assertFalse($signer1->verify($signed2));
        $this->tester->assertTrue($signer2->verify($signed2));
        $this->tester->assertFalse($signer2->verify($signed1));
    }
}
