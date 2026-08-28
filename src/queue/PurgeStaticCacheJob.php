<?php

namespace craft\cloud\queue;

use craft\cloud\Helper;
use craft\cloud\Module;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use GuzzleHttp\RequestOptions;

class PurgeStaticCacheJob extends BaseJob
{
    /**
     * @var string[]
     */
    public array $tags = [];

    /**
     * @var string[]
     */
    public array $fetchUrls = [];

    protected function defaultDescription(): ?string
    {
        return Translation::prep('app', 'Purging static cache');
    }

    public function execute($queue): void
    {
        Module::info('Purging tags', [
            'tags' => $this->tags,
            'fetchUrls' => $this->fetchUrls,
        ]);

        $payload = [
            'tags' => $this->tags,
        ];

        if ($this->fetchUrls !== []) {
            $payload['fetchUrls'] = $this->fetchUrls;
        }

        Helper::createGatewayApiClient()->request('POST', 'cache/purge', [
            RequestOptions::JSON => $payload,
            RequestOptions::TIMEOUT => 40,
        ]);
    }
}
