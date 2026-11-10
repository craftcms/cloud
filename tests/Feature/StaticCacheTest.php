<?php

declare(strict_types=1);

use craft\cloud\CloudServiceProvider;
use craft\cloud\Console\StaticCacheCommand;
use craft\cloud\Http\Middleware\CacheResponse;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Element\ElementCaches;
use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Http\Middleware\HandleTokenRequest;
use CraftCms\Cms\Http\Middleware\ResolveSite;
use CraftCms\Cms\Http\Middleware\SetHeaders;
use CraftCms\Cms\Http\Mixins\RequestMixin;
use CraftCms\Cms\Http\ResponseHeaders;
use CraftCms\Cms\Route\MatchedElement;
use CraftCms\Cms\Utility\Utilities\ClearCaches;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Verifier;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Cookie\CookieJar;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->app['env'] = 'testing';
    $this->app->instance('log', new NullLogger());
    $this->app->instance(GeneralConfig::class, GeneralConfig::create());
    $this->app->singleton('cache', fn($app) => new CacheManager($app));
    $this->app->instance('encrypter', new Encrypter(random_bytes(32), 'aes-256-cbc'));
    $this->app->singleton('cookie', fn() => new CookieJar());
    Facade::clearResolvedInstances();
    Request::mixin(new RequestMixin());
    $this->config->set('session.cookie', 'craft_session');
    $this->config->set('cloud', ['environment_id' => '12345678-abcd', 'signing_key' => 'test-key']);
    $this->router = $this->app->make(Router::class);
    $this->app->instance(KernelContract::class, new Kernel($this->app, $this->router));
    // The groups core defines before Cloud's provider boots, with the middleware that sets or removes headers.
    $this->router->middlewareGroup('web', [EncryptCookies::class, AddQueuedCookiesToResponse::class]);
    $this->router->middlewareGroup('craft', [SetHeaders::class]);
    $this->router->middlewareGroup('craft.web', []);
    $this->server('CRAFT_CLOUD', '1');
    $this->app->register(CloudServiceProvider::class);
    $this->app->boot();
    Carbon::setTestNow('2026-11-10 12:00:00 UTC');

    // Runs a site route through the same middleware stack and priority sorting as Laravel's router.
    $this->respond = function (Closure $handler, ?Request $request = null): Response {
        $request ??= Request::create('/news');
        $this->app->instance('request', $request);
        $route = $this->router->get('/news', $handler)->middleware(['web', 'craft', 'craft.web']);
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

function entry(string $uri, bool $homepage = false, bool $draft = false): Entry
{
    $entry = test()->createStub(Entry::class);
    $entry->id = 42;
    $entry->uri = $uri;
    $entry->method('getIsHomepage')->willReturn($homepage);
    $entry->method('getIsDraft')->willReturn($draft);
    $entry->method('getUrl')->willReturn('https://example.com/' . ($homepage ? '' : $uri));

    return $entry;
}

it('runs outside core and cookie middleware on site routes', function (): void {
    $route = $this->router->get('/news', fn() => 'news')->middleware(['web', 'craft', 'craft.web']);

    expect($this->router->gatherRouteMiddleware($route))->toBe([
        CacheResponse::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        SetHeaders::class,
    ]);
});

it('applies Cloud defaults to responses without a cache policy, capped at element expiry', function (?int $expiry): void {
    $response = ($this->respond)(function () use ($expiry): Response {
        if ($expiry !== null) {
            $this->app->make(ElementCaches::class)->setCacheExpiryDate(now()->addSeconds($expiry));
        }

        return new Response('page');
    });

    expect($response->headers->get('CDN-Cache-Control'))
        ->toBe('max-age=' . ($expiry ?? 31_536_000) . ', public, stale-while-revalidate=3600')
        ->and($response->headers->get('Cache-Control'))
        ->toBe('no-cache, private');
})->with([null, 90]);

it('uses configured defaults', function (): void {
    $this->config->set('cloud.static_cache_duration', 456);
    $this->config->set('cloud.static_cache_stale_while_revalidate_duration', 19);

    $response = ($this->respond)(fn() => new Response('page'), Request::create('/news', 'HEAD'));

    expect($response->headers->get('CDN-Cache-Control'))->toBe('max-age=456, public, stale-while-revalidate=19');
});

it('follows the lifetime set with core response headers', function (): void {
    $response = ($this->respond)(function (): Response {
        $this->app->make(ResponseHeaders::class)->setCache(600);
        $this->app->make(ElementCaches::class)->setCacheExpiryDate(now()->addSeconds(900));

        return new Response('page');
    });

    expect($response->headers->get('CDN-Cache-Control'))
        ->toBe('max-age=600, public')
        ->and($response->headers->getCacheControlDirective('max-age'))
        ->toBe('600');
});

it('follows the response’s own lifetime, preferring s-maxage and capping it at element expiry', function (
    array $headers,
    ?int $expiry,
    string $expected,
): void {
    $response = ($this->respond)(function () use ($headers, $expiry): Response {
        if ($expiry !== null) {
            $this->app->make(ElementCaches::class)->setCacheExpiryDate(now()->addSeconds($expiry));
        }

        return new Response('page', headers: $headers);
    });

    expect($response->headers->get('CDN-Cache-Control'))
        ->toBe($expected)
        ->and($response->headers->get('Cache-Control'))
        ->toBe(new Response(headers: $headers)->headers->get('Cache-Control'));
})->with([
    'max-age' => [['Cache-Control' => 'public, max-age=60'], null, 'max-age=60, public'],
    's-maxage' => [
        ['Cache-Control' => 'public, max-age=60, s-maxage=600, stale-if-error=30'],
        null,
        'max-age=600, public, stale-if-error=30',
    ],
    'capped' => [['Cache-Control' => 'public, max-age=60, s-maxage=600'], 90, 'max-age=90, public'],
    'shorter than expiry' => [['Cache-Control' => 'public, max-age=60'], 90, 'max-age=60, public'],
    'expires' => [
        ['Expires' => 'Tue, 10 Nov 2026 12:05:00 GMT', 'Date' => 'Tue, 10 Nov 2026 12:00:00 GMT'],
        null,
        'max-age=300, public',
    ],
    'existing CDN header' => [
        ['CDN-Cache-Control' => 'public, max-age=500, s-maxage=800'],
        90,
        'max-age=90, public, s-maxage=90',
    ],
]);

it('keeps restricted responses out of the CDN', function (Closure $policy): void {
    $response = ($this->respond)(function () use ($policy): Response {
        $response = new Response('page');
        $policy($this->app, $response);

        return $response;
    });

    expect($response->headers->get('CDN-Cache-Control'))->toBe('no-store');
})->with([
    'noCache()' => fn($app) => $app->make(ResponseHeaders::class)->noCache(),
    'setCache(0)' => fn($app) => $app->make(ResponseHeaders::class)->setCache(0),
    'private' => fn($app, Response $response) => $response->setPrivate()->setMaxAge(60),
    'no-cache' => fn($app, Response $response) => $response->headers->set('Cache-Control', 'public, no-cache'),
]);

it('keeps personal and tokenized responses out of the CDN', function (string $scenario): void {
    $request = Request::create('/news', cookies: $scenario === 'session cookie' ? ['craft_session' => 'x'] : []);

    match ($scenario) {
        'route token' => Context::addHidden(HandleTokenRequest::HAD_TOKEN_KEY, true),
        'site token' => Context::addHidden(ResolveSite::HAD_SITE_TOKEN_KEY, true),
        'authorization' => $request->headers->set('Authorization', 'Bearer x'),
        default => null,
    };

    $response = ($this->respond)(function () use ($scenario): Response {
        $response = new Response('page', headers: ['CDN-Cache-Control' => 'public, max-age=600']);
        $this->app->make(ResponseHeaders::class)->setCache(600);

        if ($scenario === 'response cookie') {
            $response->headers->setCookie(new Cookie('preference', 'dark'));
        }

        if ($scenario === 'queued cookie') {
            $this->app->make('cookie')->queue('preference', 'dark');
        }

        return $response;
    }, $request);

    expect($response->headers->get('CDN-Cache-Control'))
        ->toBe('no-store')
        ->and($response->headers->has('Cache-Tag'))
        ->toBeFalse();
})->with(['session cookie', 'response cookie', 'queued cookie', 'route token', 'site token', 'authorization']);

it('caches requests with unrelated cookies', function (): void {
    $response = ($this->respond)(fn() => new Response('page'), Request::create('/news', cookies: ['analytics' => 'x']));

    expect($response->headers->get('CDN-Cache-Control'))->toStartWith('max-age=31536000');
});

it('leaves unsafe methods and error responses alone', function (string $method, int $status): void {
    $response = ($this->respond)(
        function () use ($status): Response {
            $this->app->make(ElementCaches::class)->collectCacheTags(['element::1']);

            return new Response('page', $status);
        },
        Request::create('/news', $method),
    );

    expect($response->headers->has('CDN-Cache-Control'))
        ->toBeFalse()
        ->and($response->headers->has('Cache-Tag'))
        ->toBeFalse()
        ->and($this->app->make(ElementCaches::class)->isCollectingCacheInfo())
        ->toBeFalse();
})->with([['POST', 200], ['GET', 404]]);

it('tags responses with collected, matched element and custom tags', function (): void {
    $entry = entry('news/hello');
    $response = ($this->respond)(function () use ($entry): Response {
        MatchedElement::set($entry, 'templates/render');
        $this->app->make(ElementCaches::class)->collectCacheTags(['news', 'element::42']);

        return new Response('page', headers: ['Cache-Tag' => 'custom tag,custom tag']);
    });

    // Short environment ID plus the CRC32 of each tag: news, element::42, element, element::<class>.
    expect(explode(',', $response->headers->get('Cache-Tag')))->toBe([
        '123456781dd39950',
        '12345678fe708f7a',
        '1234567841405e39',
        sprintf('12345678%x', crc32('element::' . $entry::class)),
        'custom%20tag',
    ]);
});

it('bounds tags by count, bytes and individual length with the overflow tag', function (array $tags): void {
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

it('batches invalidations and URI purges into one signed gateway request', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    Event::dispatch(new ElementCachesInvalidated(['element::42']));
    $this->app->make(ElementCaches::class)->invalidateAll();
    Event::dispatch(new ElementSaved(entry('news/hello/')));
    Event::dispatch(new ElementSaved(entry('__home__', homepage: true)));
    Event::dispatch(new ElementDeleted(entry('news/gone')));
    Event::dispatch(new ElementSaved(entry('news/draft', draft: true)));
    Event::dispatch(new ElementCachesInvalidated(['draft'], entry('news/draft', draft: true)));
    Http::assertNothingSent();

    $this->app->terminate();

    Http::assertSentCount(1);
    Http::assertSent(function (ClientRequest $request): bool {
        expect($request->url())
            ->toBe('https://gateway.craft.cloud/api/12345678-abcd/cache/purge')
            ->and($request->data())
            ->toBe([
                'tags' => [
                    '12345678fe708f7a',
                    '1234567841405e39',
                    '12345678-abcd:uri:/news/hello',
                    '12345678-abcd:uri:/',
                    '12345678-abcd:uri:/news/gone',
                ],
                'fetchUrls' => ['https://example.com/news/hello/', 'https://example.com/'],
            ])
            ->and(new Verifier(new HmacSha256('test-key'))->verify($request->toPsrRequest()))
            ->toBeTrue();

        return true;
    });
});

it('purges after each queue job', function (): void {
    Http::fake();

    Event::dispatch(new ElementCachesInvalidated(['element::42']));
    Event::dispatch(new JobProcessed('sync', test()->createStub(Illuminate\Contracts\Queue\Job::class)));
    $this->app->terminate();

    Http::assertSentCount(1);
});

it('logs gateway failures without failing termination', function (): void {
    $handler = new Monolog\Handler\TestHandler();
    $this->app->instance('log', new Monolog\Logger('test', [$handler]));
    Facade::clearResolvedInstance('log');
    Http::fake(['*' => Http::response('', 500)]);

    Event::dispatch(new ElementCachesInvalidated(['element']));
    $this->app->terminate();

    expect($handler->hasErrorRecords())->toBeTrue();
    Http::assertSentCount(1);
});

it('adds a Clear Caches option that purges pages, assets and transforms', function (): void {
    Http::fake();
    $option = collect(ClearCaches::cacheOptions())->firstWhere('key', 'craft-cloud-static-cache');

    $option['action']();

    Http::assertSent(
        fn(ClientRequest $request): bool => $request->data() === [
            'tags' => ['12345678-abcd:uri', '12345678-abcd:cdn', '12345678-abcd:rasterize'],
        ],
    );
});

it('purges from the console', function (string $command, array $arguments, array $payload): void {
    Http::fake();
    $instance = collect(StaticCacheCommand::all())->first(fn(StaticCacheCommand $c) => $c->getName() === $command);
    $instance->setLaravel($this->app);

    expect($instance->run(new ArrayInput($arguments), new BufferedOutput()))->toBe(0);
    Http::assertSent(fn(ClientRequest $request): bool => $request->data() === $payload);
})->with([
    [
        'cloud:static-cache:purge-all',
        [],
        ['tags' => ['12345678-abcd:uri', '12345678-abcd:cdn', '12345678-abcd:rasterize']],
    ],
    ['cloud:static-cache:purge-cdn', [], ['tags' => ['12345678-abcd:cdn', '12345678-abcd:rasterize']]],
    ['cloud:static-cache:purge-origin', [], ['tags' => ['12345678-abcd:uri']]],
    ['cloud:static-cache:purge-tags', ['tags' => ['a b', 'c']], ['tags' => ['a%20b', 'c']]],
    ['cloud:static-cache:purge-prefixes', ['prefixes' => ['example.com/blog']], ['prefixes' => ['example.com/blog']]],
]);
