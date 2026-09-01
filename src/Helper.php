<?php

namespace craft\cloud;

use Craft;
use craft\cloud\fs\BuildArtifactsFs;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use yii\base\Exception;

class Helper
{
    public static function isCraftCloud(): bool
    {
        return App::env('CRAFT_CLOUD') ?? App::env('AWS_LAMBDA_RUNTIME_API') ?? false;
    }

    public static function artifactUrl(string $path = ''): string
    {
        return (new BuildArtifactsFs())->createUrl($path);
    }

    /**
     * @deprecated Use createGatewayApiClient()->request() instead.
     */
    public static function makeGatewayApiRequest(iterable $headers): ResponseInterface
    {
        $normalizeHeaderValue = fn(mixed $value): array => Collection::make(is_iterable($value) ? $value : [$value])
            ->flatMap(fn(mixed $value) => explode(',', (string) $value))
            ->map(fn(string $value) => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $prefixes = [];

        foreach ($headers as $name => $value) {
            if (HeaderEnum::CACHE_PURGE_PREFIX->matches((string) $name)) {
                $prefixes = $normalizeHeaderValue($value);
                break;
            }
        }

        if (empty($prefixes)) {
            throw new Exception('Gateway API requests require a Cache-Purge-Prefix header.');
        }

        return self::createGatewayApiClient()->request(
            'POST',
            'cache/purge',
            [
                RequestOptions::JSON => ['prefixes' => $prefixes],
            ],
        );
    }

    /** @internal */
    public static function createGatewayApiClient(): Client
    {
        $module = Module::getInstance();
        $config = $module->getConfig();

        if (!$config->environmentId) {
            throw new Exception('Gateway API requests require an environment ID.');
        }

        if (!$config->signingKey) {
            throw new Exception('Gateway API requests require a signing key.');
        }

        $headers = [];

        if ($config->getDevMode()) {
            $headers[HeaderEnum::DEV_MODE->value] = '1';
        }

        return Craft::createGuzzleClient([
            'base_uri' => sprintf(
                '%s/api/%s/',
                rtrim($config->gatewayBaseUrl, '/'),
                rawurlencode($config->environmentId),
            ),
            'handler' => $module->getRequestSigner()->createHandlerStack(),
            RequestOptions::HEADERS => $headers,
        ]);
    }
}
