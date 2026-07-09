<?php

namespace craft\cloud\tests\unit;

use Codeception\Test\Unit;
use craft\cloud\web\Session;
use ReflectionMethod;

class SessionTest extends Unit
{
    public function testCookieHeaderValuesAreRedacted(): void
    {
        $this->assertSame(
            ['[redacted]', '[redacted]'],
            $this->loggableHeaderValues('Set-Cookie', [
                'CraftSessionId=session-id; path=/',
                'other=value',
            ]),
        );

        $this->assertSame(
            ['no-store'],
            $this->loggableHeaderValues('Cache-Control', ['no-store']),
        );
    }

    private function loggableHeaderValues(string $name, array $values): array
    {
        $method = new ReflectionMethod(Session::class, 'loggableHeaderValues');
        $method->setAccessible(true);

        return $method->invoke(new Session(), $name, $values);
    }
}
