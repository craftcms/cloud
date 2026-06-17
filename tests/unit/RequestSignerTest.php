<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\signing\RequestSigner;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Verifier;
use Psr\Http\Message\RequestInterface;

class RequestSignerTest extends Unit
{
    private const DEFAULT_EXPIRES_AFTER = 300;

    public function testSignsRequestsToAnyDestination(): void
    {
        $request = new Request('POST', 'https://example.test/webhooks/deploy?foo=bar');

        $signedRequest = (new RequestSigner('test-signing-key'))->sign($request);

        $this->assertSame('', $request->getHeaderLine('Signature'));
        $this->assertSignedRequest($signedRequest);
    }

    public function testCreatesHandlerStackForSignedRequests(): void
    {
        $capturedRequest = null;
        $handlerStack = new HandlerStack(function(RequestInterface $request) use (&$capturedRequest) {
            $capturedRequest = $request;

            return Create::promiseFor(new Response(204));
        });

        $handler = (new RequestSigner('test-signing-key'))
            ->createHandlerStack($handlerStack)
            ->resolve();

        $response = $handler(new Request('GET', 'https://consumer.example.test/status'), [])->wait();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertInstanceOf(RequestInterface::class, $capturedRequest);
        $this->assertSignedRequest($capturedRequest);
    }

    private function assertSignedRequest(RequestInterface $request): void
    {
        $this->assertNotSame('', $request->getHeaderLine('Signature'));
        $this->assertTrue((new Verifier(new HmacSha256('test-signing-key')))->verify($request));

        $signatureInput = $request->getHeaderLine('Signature-Input');

        $this->assertStringContainsString('"@method"', $signatureInput);
        $this->assertStringContainsString('"@target-uri"', $signatureInput);
        $this->assertStringContainsString('alg="hmac-sha256"', $signatureInput);
        $this->assertStringContainsString('keyid="hmac"', $signatureInput);

        $matches = [];
        $this->assertSame(1, preg_match('/created=(\d+);expires=(\d+)/', $signatureInput, $matches));
        $this->assertSame(self::DEFAULT_EXPIRES_AFTER, (int) $matches[2] - (int) $matches[1]);
    }
}
