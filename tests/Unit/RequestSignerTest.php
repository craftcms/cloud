<?php

declare(strict_types=1);

use craft\cloud\signing\RequestSigner;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Exception\VerificationException;
use HttpMessageSignatures\Verifier;
use Psr\Http\Message\RequestInterface;

function expectSignedRequest(RequestInterface $request): void
{
    expect(new Verifier(new HmacSha256('test-signing-key'))->verify($request))->toBeTrue();

    $signatureInput = $request->getHeaderLine('Signature-Input');

    expect($signatureInput)->toContain(
        '"@method"',
        '"@target-uri"',
        'alg="hmac-sha256"',
        'keyid="hmac"',
        'tag="craft-cloud"',
    );
    expect(preg_match('/created=(\d+);expires=(\d+)/', $signatureInput, $matches))->toBe(1);
    expect((int) $matches[2] - (int) $matches[1])->toBe(300);
}

it('signs requests without mutating the original', function (): void {
    $request = new Request('POST', 'https://example.test/webhooks/deploy?foo=bar');

    $signedRequest = new RequestSigner('test-signing-key')->sign($request);

    expect($request->getHeaderLine('Signature'))->toBe('');
    expectSignedRequest($signedRequest);
});

it('signs requests sent through its handler stack', function (): void {
    $capturedRequest = null;
    $handlerStack = new HandlerStack(function (RequestInterface $request) use (&$capturedRequest) {
        $capturedRequest = $request;

        return Create::promiseFor(new Response(204));
    });

    $handler = new RequestSigner('test-signing-key')
        ->createHandlerStack($handlerStack)
        ->resolve();
    $response = $handler(new Request('GET', 'https://consumer.example.test/status'), [])->wait();

    expect($response->getStatusCode())->toBe(204);
    expectSignedRequest($capturedRequest);
});

it('rejects a tampered signature tag', function (): void {
    $request = new RequestSigner('test-signing-key')->sign(new Request('GET', 'https://example.test/status'));
    $request = $request->withHeader('Signature-Input', str_replace(
        search: 'tag="craft-cloud"',
        replace: 'tag="other"',
        subject: $request->getHeaderLine('Signature-Input'),
    ));

    new Verifier(new HmacSha256('test-signing-key'))->verify($request);
})->throws(VerificationException::class);
