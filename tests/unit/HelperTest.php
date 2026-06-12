<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\Helper;
use craft\cloud\HeaderEnum;
use craft\cloud\Module;
use GuzzleHttp\RequestOptions;
use ReflectionMethod;

class HelperTest extends Unit
{
    private string|false $craftCloudDevMode = false;
    private ?Module $previousModule = null;

    protected function _before(): void
    {
        parent::_before();

        $this->craftCloudDevMode = getenv('CRAFT_CLOUD_DEV_MODE');
        $this->previousModule = Module::getInstance();
        Module::setInstance(new Module('cloud'));

        $config = Module::getInstance()->getConfig();

        $config->environmentId = '123-environment-id';
        $config->gatewayBaseUrl = 'https://gateway.craft.cloud';
        $config->signingKey = 'test-signing-key';
    }

    protected function _after(): void
    {
        if ($this->craftCloudDevMode === false) {
            putenv('CRAFT_CLOUD_DEV_MODE');
        } else {
            putenv("CRAFT_CLOUD_DEV_MODE={$this->craftCloudDevMode}");
        }

        Module::setInstance($this->previousModule);

        parent::_after();
    }

    public function testGatewayApiClientsUseEnvironmentApiRoute(): void
    {
        $this->assertSame(
            'https://gateway.craft.cloud/api/123-environment-id/',
            (string) Helper::createGatewayApiClient()->getConfig('base_uri'),
        );
    }

    public function testGatewayApiClientsUseConfiguredGatewayBaseUrl(): void
    {
        Module::getInstance()->getConfig()->gatewayBaseUrl = 'https://gateway.craftstaging.cloud/';

        $this->assertSame(
            'https://gateway.craftstaging.cloud/api/123-environment-id/',
            (string) Helper::createGatewayApiClient()->getConfig('base_uri'),
        );
    }

    public function testGatewayApiClientsAddAuthenticationHeaders(): void
    {
        $client = Helper::createGatewayApiClient();
        $headers = $client->getConfig(RequestOptions::HEADERS);

        $this->assertSame('Bearer test-signing-key', $headers['X-Gateway-Authorization'] ?? null);
    }

    public function testGatewayApiClientsAddDevModeHeader(): void
    {
        putenv('CRAFT_CLOUD_DEV_MODE=1');

        $client = Helper::createGatewayApiClient();
        $headers = $client->getConfig(RequestOptions::HEADERS);

        $this->assertSame('1', $headers[HeaderEnum::DEV_MODE->value] ?? null);
    }

    public function testGatewayApiRequestHelperKeepsLegacySignature(): void
    {
        $method = new ReflectionMethod(Helper::class, 'makeGatewayApiRequest');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('headers', $parameters[0]->getName());
    }
}
