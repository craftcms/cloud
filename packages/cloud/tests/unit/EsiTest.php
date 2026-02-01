<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\Esi;
use craft\cloud\UrlSigner;
use craft\web\twig\TemplateLoaderException;
use InvalidArgumentException;

/**
 * Unit tests for ESI functionality that don't require Craft to be initialized.
 * These tests focus on variable validation and basic ESI behavior.
 */
class EsiTest extends Unit
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

    public function testValidateVariablesThrowsExceptionForObjects()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be a primitive value or array');

        $esi = new Esi($this->urlSigner, true);
        $template = 'test-template';
        $variables = [
            'object' => new \stdClass(),
        ];

        $esi->render($template, $variables);
    }

    public function testRenderAcceptsScalarValuesAndArrays()
    {
        $esi = new Esi($this->urlSigner, true);
        $template = 'test-template';

        $this->assertStringContainsString('<esi:include', $esi->render($template, []));

        $this->assertStringContainsString('<esi:include', $esi->render($template, [
            'string' => 'value',
            'int' => 123,
            'float' => 45.67,
            'bool' => true,
            'null' => null,
            'array' => ['foo', 'bar', 'baz'],
            'nested' => [
                'foo' => 'bar',
                'deep' => [
                    'value' => 123,
                    'items' => [1, 2, 3],
                ],
            ],
            'mixed' => [
                'scalar' => 'value',
                'number' => 42,
                'list' => ['a', 'b', 'c'],
            ],
        ]));
    }

    public function testRendersTemplateWhenInstructed()
    {
        $this->expectException(TemplateLoaderException::class);
        $this->expectExceptionMessage('Unable to find the template “test-template”.');

        $esi = new Esi($this->urlSigner, false);
        $template = 'test-template';
        $esi->render($template);
    }
}
