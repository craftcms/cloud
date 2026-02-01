<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\UrlSigner;

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

    public function testSignWithExistingQueryParameters()
    {
        $url = 'https://example.com/test?foo=bar&baz=qux';
        $signedUrl = $this->urlSigner->sign($url);

        $this->tester->assertStringContainsString('foo=bar', $signedUrl);
        $this->tester->assertStringContainsString('baz=qux', $signedUrl);
        $this->tester->assertStringContainsString('&s=', $signedUrl);
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
