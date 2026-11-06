<?php

declare(strict_types=1);

use craft\cloud\signing\UrlSigner;
use League\Uri\Components\Query;

beforeEach(function (): void {
    $this->urlSigner = new UrlSigner('test-signing-key');
});

it('adds a signature parameter', function (): void {
    $url = 'https://example.com/test';
    $signedUrl = $this->urlSigner->sign($url);

    expect($signedUrl)->toStartWith($url . '?s=');
    expect($this->urlSigner->verify($url))->toBeFalse();
    expect($this->urlSigner->verify($signedUrl))->toBeTrue();
});

it('rejects tampered, wrong, and malformed signatures', function (): void {
    $signedUrl = $this->urlSigner->sign('https://example.com/test');

    expect($this->urlSigner->verify($signedUrl . '&tamper=true'))->toBeFalse();
    expect($this->urlSigner->verify('https://example.com/test?s=wrongsignature'))->toBeFalse();
    expect($this->urlSigner->verify('https://exa mple.com/test?s=wrongsignature'))->toBeFalse();
});

it('signs URLs with existing query parameters', function (): void {
    $signedUrl = $this->urlSigner->sign('https://example.com/test?foo=bar&baz=qux');

    expect($signedUrl)->toContain('foo=bar', 'baz=qux', '&s=');
    expect($this->urlSigner->verify($signedUrl))->toBeTrue();
});

it('normalizes query values before signing', function (string $url, string $expected, ?string $unexpected): void {
    $signedUrl = $this->urlSigner->sign($url);

    expect($signedUrl)->toContain($expected);

    if ($unexpected !== null) {
        expect($signedUrl)->not->toContain($unexpected);
    }

    expect($this->urlSigner->verify($signedUrl))->toBeTrue();
})->with([
    'unencoded slashes' => [
        'https://example.com/actions/cloud/esi/render-template?template=_includes/head',
        'template=_includes%2Fhead',
        null,
    ],
    'form-encoded spaces' => [
        'https://example.com/actions/cloud/esi/render-template?variables%5Btitle%5D=Hello+world',
        'variables%5Btitle%5D=Hello%20world',
        'variables%5Btitle%5D=Hello%2Bworld',
    ],
]);

it('rejects non-canonical query values', function (string $signed, string $presented): void {
    $signedUrl = $this->urlSigner->sign("https://example.com/actions/cloud/esi/render-template?{$signed}");

    expect($this->urlSigner->verify(str_replace($signed, $presented, $signedUrl)))->toBeFalse();
})->with([
    'raw slash' => ['template=_includes%2Fhead', 'template=_includes/head'],
    'plus that parses differently' => ['template=foo%2Bbar', 'template=foo+bar'],
    'alternate space encoding' => ['variables%5Btitle%5D=Hello%20world', 'variables%5Btitle%5D=Hello+world'],
]);

it('replaces an existing signature parameter', function (): void {
    $signedUrl = $this->urlSigner->sign('https://example.com/test?s=old&foo=bar');

    expect(Query::fromUri($signedUrl)->parameters()['s'])->not->toBe('old');
    expect($this->urlSigner->verify($signedUrl))->toBeTrue();
});

it('supports a custom signature parameter', function (): void {
    $signer = new UrlSigner('test-key', 'signature');
    $signedUrl = $signer->sign('https://example.com/test');

    expect($signedUrl)->toContain('?signature=');
    expect($signer->verify($signedUrl))->toBeTrue();
});

it('only verifies signatures made with the same key', function (): void {
    $signer1 = new UrlSigner('key1');
    $signer2 = new UrlSigner('key2');
    $signed1 = $signer1->sign('https://example.com/test');
    $signed2 = $signer2->sign('https://example.com/test');

    expect($signed1)->not->toBe($signed2);
    expect($signer1->verify($signed1))->toBeTrue();
    expect($signer1->verify($signed2))->toBeFalse();
    expect($signer2->verify($signed2))->toBeTrue();
    expect($signer2->verify($signed1))->toBeFalse();
});
