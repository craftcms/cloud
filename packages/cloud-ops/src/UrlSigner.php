<?php

namespace craft\cloud\ops;

use Craft;
use League\Uri\Components\Query;
use League\Uri\Modifier;

class UrlSigner
{
    public function __construct(
        private readonly string $signingKey,
        private readonly string $signatureParameter = 's',
    ) {
    }

    public function sign(string $url): string
    {
        $data = $this->getSigningData($url);
        $signature = hash_hmac('sha256', $data, $this->signingKey);

        return Modifier::from($url)->appendQueryParameters([
            $this->signatureParameter => $signature,
        ]);
    }

    private function getSigningData(string $url): string
    {
        return Modifier::from($url)
            ->removeQueryParameters($this->signatureParameter)
            ->sortQuery();
    }

    public function verify(string $url): bool
    {
        $providedSignature = Query::fromUri($url)->get($this->signatureParameter);

        if (!$providedSignature) {
            Craft::info([
                'message' => 'Missing signature',
                'url' => $url,
                'signatureParameter' => $this->signatureParameter,
            ], __METHOD__);

            return false;
        }

        $data = $this->getSigningData($url);

        $verified = hash_equals(
            hash_hmac('sha256', $data, $this->signingKey),
            $providedSignature,
        );

        if (!$verified) {
            Craft::info([
                'message' => 'Invalid signature',
                'providedSignature' => $providedSignature,
                'data' => $data,
                'url' => $url,
            ], __METHOD__);
        }

        return $verified;
    }
}
