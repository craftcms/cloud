<?php

declare(strict_types=1);

use craft\cloud\CloudServiceProvider;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementCaches;
use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Http\CacheControl;
use CraftCms\Cms\Http\Events\CacheControlApplied;
use CraftCms\Cms\Http\Middleware\ApplyCacheControl;
use CraftCms\Cms\Http\Mixins\RequestMixin;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Verifier;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function (): void {
    $this->app->instance('log', new NullLogger());
    $this->app->instance(GeneralConfig::class, GeneralConfig::create());
    $this->app->singleton('cache', fn($app) => new CacheManager($app));
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->app);
    Request::mixin(new RequestMixin());
    $this->config->set('session.cookie', 'craft_session');
    $this->config->set('cloud', ['environment_id' => '12345678-abcd', 'signing_key' => 'test-key']);
    $this->router = $this->app->make(Router::class);
    $kernel = new Kernel($this->app, $this->router);
    $this->app->instance(KernelContract::class, $kernel);
    $kernel->addToMiddlewarePriorityBefore(
        Illuminate\Cookie\Middleware\EncryptCookies::class,
        ApplyCacheControl::class,
    );
    $this->router->middlewareGroup('craft.web', [ApplyCacheControl::class]);
    $this->server('CRAFT_CLOUD', '1');
    $this->app->register(CloudServiceProvider::class);
    $this->app->boot();
    // Keep element invalidation local while retaining the real CMS invalidation event.
    $this->config->set('cache.default', 'array');
    $this->checkpoint = null;
    Event::listen(CacheControlApplied::class, function (CacheControlApplied $event): void {
        $this->checkpoint = $event;
    });
    Carbon::setTestNow('2026-11-10 12:00:00 UTC');

    $this->respond = function (Closure $handler, ?Request $request = null): Response {
        $request ??= Request::create('/news');
        $this->app->instance('request', $request);
        $route = $this->router->get('/news', $handler)->middleware('craft.web');
        $request->setRouteResolver(fn() => $route);

        return new Pipeline($this->app)
            ->send($request)
            ->through($this->router->gatherRouteMiddleware($route))
            ->then(fn() => $handler());
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
    Request::flushMacros();
});

it('translates full policy without changing the checkpoint snapshot or browser lifetime', function (): void {
    $response = ($this->respond)(function (): Response {
        $this->app
            ->make(CacheControl::class)
            ->setMaxAge(123)
            ->setSharedMaxAge(789)
            ->setStaleWhileRevalidate(37)
            ->setStaleIfError(91);

        return new Response('news', headers: [
            'Cache-Control' => 'public, must-revalidate, proxy-revalidate, immutable, no-transform',
            'Surrogate-Control' => 'content="ESI/1.0"',
        ]);
    });

    expect($response->headers->getCacheControlDirective('max-age'))
        ->toBe('123')
        ->and($response->headers->getCacheControlDirective('s-maxage'))
        ->toBe('789')
        ->and($response->headers->get('Surrogate-Control'))
        ->toBe('content="ESI/1.0"');
    $edge = new Response(headers: ['Cache-Control' => $response->headers->get('CDN-Cache-Control')]);
    expect($edge->headers->getCacheControlDirective('max-age'))
        ->toBe('789')
        ->and($edge->headers->hasCacheControlDirective('s-maxage'))
        ->toBeFalse()
        ->and($this->checkpoint->policy->headers->getCacheControlDirective('s-maxage'))
        ->toBe('789');
    foreach ([
        'must-revalidate',
        'proxy-revalidate',
        'immutable',
        'no-transform',
        'stale-while-revalidate',
        'stale-if-error',
    ] as $directive) {
        expect($edge->headers->getCacheControlDirective($directive))
            ->toBe($response->headers->getCacheControlDirective($directive));
    }
});

it('supplies Cloud defaults before dependency caps and retains the reason', function (bool $expiry): void {
    $response = ($this->respond)(function () use ($expiry): Response {
        if ($expiry) {
            $this->app->make(ElementCaches::class)->setCacheExpiryDate(now()->addSeconds(90));
        }

        return new Response('page');
    });

    expect($response->headers->getCacheControlDirective('max-age'))
        ->toBe('0')
        ->and($response->headers->getCacheControlDirective('s-maxage'))
        ->toBe($expiry ? '90' : '31536000')
        ->and($response->headers->get('CDN-Cache-Control'))
        ->toContain('max-age=' . ($expiry ? '90' : '31536000'))
        ->and($response->headers->get('CDN-Cache-Control'))
        ->toContain('stale-while-revalidate=3600')
        ->and(in_array('dependency-expiry', array_column($this->checkpoint->reasons, 'code'), strict: true))
        ->toBe($expiry);
})->with([false, true]);

it('caps explicit browser and shared lifetimes without extending shorter freshness', function (
    int $browser,
    int $shared,
    int $age,
    int $expectedBrowser,
    int $expectedShared,
): void {
    $response = ($this->respond)(function () use ($browser, $shared, $age): Response {
        $this->app->make(CacheControl::class)->setMaxAge($browser)->setSharedMaxAge($shared);
        $this->app->make(ElementCaches::class)->setCacheExpiryDate(now()->addSeconds(90));

        return new Response('page', headers: ['Age' => (string) $age]);
    });

    expect($response->headers->getCacheControlDirective('max-age'))
        ->toBe((string) $expectedBrowser)
        ->and($response->headers->getCacheControlDirective('s-maxage'))
        ->toBe((string) $expectedShared)
        ->and($response->headers->get('CDN-Cache-Control'))
        ->toContain('max-age=' . $expectedShared);
})->with([
    [123, 789, 11, 101, 101],
    [20,  40,  11, 20,  40],
]);

it('does not replace explicit freshness or storage restrictions with defaults', function (string $header): void {
    $response = ($this->respond)(fn() => new Response('page', headers: ['Cache-Control' => $header]));

    expect($response->headers->hasCacheControlDirective('s-maxage'))
        ->toBeFalse()
        ->and($response->headers->get('CDN-Cache-Control'))
        ->toBe($response->headers->get('Cache-Control'))
        ->and($response->headers->get('CDN-Cache-Control'))
        ->not->toContain('31536000');
})->with(['private', 'no-store', 'no-cache', 'public, max-age=17', 'private, max-age=23']);

it('allows HEAD and unrelated incoming cookies while honoring configured defaults', function (): void {
    $this->config->set('cloud.static_cache_duration', 456);
    $this->config->set('cloud.static_cache_stale_while_revalidate_duration', 19);
    $response = ($this->respond)(fn() => new Response('page'), Request::create('/news', 'HEAD', cookies: [
        'analytics' => 'existing',
    ]));

    expect($response->headers->getCacheControlDirective('max-age'))
        ->toBe('0')
        ->and($response->headers->get('CDN-Cache-Control'))
        ->toBe('max-age=456, public, stale-while-revalidate=19');
});

it('preserves explicit no-cache and no-store with shared freshness', function (string $restriction): void {
    $response = ($this->respond)(function () use ($restriction): Response {
        $policy = $this->app->make(CacheControl::class)->setMaxAge(123)->setSharedMaxAge(789);
        $restriction === 'no-store' ? $policy->setNoStore() : $policy->setNoCache();

        return new Response('page', headers: ['CDN-Cache-Control' => 'public, max-age=99999']);
    });

    expect($response->headers->get('CDN-Cache-Control'))->toContain($restriction);
    if ($restriction === 'no-store') {
        expect($response->headers->hasCacheControlDirective('max-age'))
            ->toBeFalse()
            ->and($response->headers->get('CDN-Cache-Control'))
            ->not->toContain('max-age');
    }
})->with(['no-cache', 'no-store']);

it('keeps cookies and unsafe requests out of shared storage', function (string $scenario): void {
    $request = Request::create(
        '/news',
        $scenario === 'post' ? 'POST' : 'GET',
        cookies: $scenario === 'incoming' ? ['craft_session' => 'existing'] : [],
    );
    if ($scenario === 'authorization') {
        $request->headers->set('Authorization', 'Bearer example');
    }
    if ($scenario === 'preview') {
        $request->headers->set('X-Craft-Preview-Token', 'preview');
    }
    if ($scenario === 'consumed-access') {
        Context::addHidden(CraftCms\Cms\Http\Middleware\ResolveSite::HAD_SITE_TOKEN_KEY, true);
    }
    $response = ($this->respond)(function () use ($scenario): Response {
        $this->app->make(CacheControl::class)->setMaxAge(123)->setSharedMaxAge(789)->setStaleWhileRevalidate(37);
        $response = $scenario === 'stream'
            ? new StreamedResponse(fn() => print 'page')
            : new Response('page', $scenario === 'error' ? 500 : 200);
        $response->headers->set('CDN-Cache-Control', 'public, max-age=99999');
        if (in_array($scenario, ['outgoing-session', 'outgoing-preference'], strict: true)) {
            $response->headers->setCookie(
                new Cookie($scenario === 'outgoing-session' ? 'craft_session' : 'preference', 'custom'),
            );
        }

        return $response;
    }, $request);

    expect($response->headers->get('CDN-Cache-Control'))
        ->toContain('private')
        ->and($response->headers->get('CDN-Cache-Control'))
        ->not->toContain('public', 's-maxage', 'stale-while-revalidate');
    if ($scenario === 'incoming') {
        expect($this->checkpoint->policy->headers->getCacheControlDirective('s-maxage'))
            ->toBe('789')
            ->and($response->headers->get('CDN-Cache-Control'))
            ->toContain('no-store');
    }
})->with([
    'incoming',
    'outgoing-session',
    'outgoing-preference',
    'authorization',
    'preview',
    'consumed-access',
    'post',
    'error',
    'stream',
]);

it('formats dependencies identically for responses and batched gateway invalidation', function (): void {
    Http::fake(['*' => Http::response('', 204)]);
    $response = ($this->respond)(function (): Response {
        $this->app->make(ElementCaches::class)->collectCacheTags(['element::42', 'news', 'element::42']);

        return new Response('page', headers: ['Cache-Tag' => 'custom tag,custom tag']);
    });
    // Independent fixtures for the existing short-environment-ID + CRC32 wire format.
    expect($response->headers->get('Cache-Tag'))->toBe('12345678fe708f7a,123456781dd39950,custom%20tag');
    Event::dispatch(new ElementCachesInvalidated(['element::42']));
    Event::dispatch(new ElementCachesInvalidated(['news', 'element::42']));
    $this->app->make(ElementCaches::class)->invalidateAll();
    Http::assertNothingSent();
    $this->app->terminate();
    Http::assertSentCount(1);
    Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
        expect($request->url())
            ->toBe('https://gateway.craft.cloud/api/12345678-abcd/cache/purge')
            ->and($request->data())
            ->toBe(['tags' => ['12345678fe708f7a', '123456781dd39950', '1234567841405e39']])
            ->and(new Verifier(new HmacSha256('test-key'))->verify($request->toPsrRequest()))
            ->toBeTrue();

        return true;
    });
});

it('bounds tags by count, bytes, and individual length with the existing overflow tag', function (array $tags): void {
    $response = ($this->respond)(fn() => new Response('page', headers: ['Cache-Tag' => implode(',', $tags)]));
    $header = $response->headers->get('Cache-Tag');
    expect($header)
        ->toStartWith('12345678-abcd:overflow,')
        ->and(strlen($header))
        ->toBeLessThanOrEqual(16_384)
        ->and(count(explode(',', $header)))
        ->toBeLessThanOrEqual(1000)
        ->and(max(array_map(strlen(...), explode(',', $header))))
        ->toBeLessThanOrEqual(1024);
})->with([
    'count' => [array_map(strval(...), range(1, end: 1001))],
    'bytes' => [array_map(fn(int $i): string => $i . str_repeat('x', times: 1000), range(1, end: 20))],
    'individual' => [['valid', str_repeat('x', times: 1025)]],
]);

it('skips draft and revision invalidations and clears pending tags across request scopes', function (string $kind): void {
    Http::fake();
    $draft = $this->createStub(ElementInterface::class);
    $draft->method('getIsDraft')->willReturn($kind !== 'revision');
    $draft->method('getIsRevision')->willReturn($kind === 'revision');
    if ($kind === 'nested') {
        $child = $this->createStub(CraftCms\Cms\Element\Contracts\NestedElementInterface::class);
        $child->method('getOwner')->willReturn($draft);
        $draft = $child;
    }
    Event::dispatch(new ElementCachesInvalidated(['draft'], $draft));
    $this->app->terminate();
    Http::assertNothingSent();

    Event::dispatch(new ElementCachesInvalidated(['first']));
    $this->app->terminate();
    $this->app->forgetScopedInstances();
    $this->app->terminate();
    Http::assertSentCount(1);
})->with(['draft', 'revision', 'nested']);

it('logs gateway failure without failing application termination', function (): void {
    $handler = new Monolog\Handler\TestHandler();
    $this->app->instance('log', new Monolog\Logger('test', [$handler]));
    Facade::clearResolvedInstance('log');
    Http::fake(['*' => Http::response('', 500)]);
    Event::dispatch(new ElementCachesInvalidated(['element']));
    $this->app->terminate();

    expect($handler->hasErrorRecords())->toBeTrue();
    Http::assertSentCount(1);
});
