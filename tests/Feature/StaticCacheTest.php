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
use CraftCms\Cms\Http\Middleware\CollectCacheInfo;
use CraftCms\Cms\Http\Middleware\SetHeaders;
use CraftCms\Cms\Http\Mixins\RequestMixin;
use CraftCms\Cms\Http\ResponseCacheInfo;
use CraftCms\Cms\Http\ResponseHeaders;
use CraftCms\Cms\Utility\Utilities\ClearCaches;
use CraftCms\HttpCache\HttpCacheServiceProvider;
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
    $this->kernel = new Kernel($this->app, $this->router);
    $this->app->instance(KernelContract::class, $this->kernel);
    // What core sets up before Cloud's provider boots: the global `CollectCacheInfo` middleware, and the route groups with
    // the middleware that sets headers and cookies.
    $this->kernel->setGlobalMiddleware([CollectCacheInfo::class]);
    $this->router->middlewareGroup('web', [EncryptCookies::class, AddQueuedCookiesToResponse::class]);
    $this->router->middlewareGroup('craft', [SetHeaders::class]);
    $this->router->middlewareGroup('craft.web', []);
    $this->app->register(HttpCacheServiceProvider::class);
    $this->server('CRAFT_CLOUD', '1');
    $this->app->register(CloudServiceProvider::class);
    $this->app->boot();
    Carbon::setTestNow('2026-11-10 12:00:00 UTC');

    // Runs a site request through the global middleware, then the route's, as Laravel's kernel and router do.
    $this->respond = function (Closure $handler, ?Request $request = null): Response {
        $this->app->forgetScopedInstances();
        $request ??= Request::create('/news');
        $this->app->instance('request', $request);
        $route = $this->router->get('/news', $handler)->middleware(['web', 'craft', 'craft.web']);
        $request->setRouteResolver(fn() => $route);

        return new Pipeline($this->app)
            ->send($request)
            ->through([...$this->kernel->getGlobalMiddleware(), ...$this->router->gatherRouteMiddleware($route)])
            ->then(fn() => $handler());
    };

    $this->expire = fn(int $seconds) => $this->app
        ->make(ElementCaches::class)
        ->setCacheExpiryDate(now()->addSeconds($seconds));
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

function purges(): array
{
    $payloads = [];

    Http::assertSent(function (ClientRequest $request) use (&$payloads): bool {
        $payloads[] = [$request->url(), $request->data()];

        return true;
    });

    return $payloads;
}

it('runs outside CollectCacheInfo, so it sees the final response', function (): void {
    $middleware = $this->kernel->getGlobalMiddleware();

    expect(array_search(CacheResponse::class, $middleware, strict: true))
        ->toBeLessThan(array_search(CollectCacheInfo::class, $middleware, strict: true));
});

it('gives pages without a cache policy Cloud’s default, capped at element expiry', function (?int $expiry): void {
    $response = ($this->respond)(function () use ($expiry): Response {
        if ($expiry !== null) {
            ($this->expire)($expiry);
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

it('copies the page’s own lifetime, preferring s-maxage, after Craft caps it at element expiry', function (
    Closure $policy,
    ?int $expiry,
    string $expected,
): void {
    $response = ($this->respond)(function () use ($policy, $expiry): Response {
        if ($expiry !== null) {
            ($this->expire)($expiry);
        }

        $response = new Response('page');
        $policy($this->app, $response);

        return $response;
    });

    expect($response->headers->get('CDN-Cache-Control'))->toBe($expected);
})->with([
    'setCache()' => [fn($app) => $app->make(ResponseHeaders::class)->setCache(600), 900, 'max-age=600, public'],
    'setCache() capped' => [fn($app) => $app->make(ResponseHeaders::class)->setCache(600), 90, 'max-age=90, public'],
    's-maxage' => [
        fn($app, Response $r) => $r->headers->set(
            'Cache-Control',
            'public, max-age=60, s-maxage=600, stale-if-error=30',
        ),
        null,
        'max-age=600, public, stale-if-error=30',
    ],
    's-maxage capped' => [
        fn($app, Response $r) => $r->setPublic()->setMaxAge(60)->setSharedMaxAge(600),
        90,
        'max-age=90, public',
    ],
    'expires' => [
        // Symfony works out the response's age from the real clock, not Carbon's.
        function ($app, Response $r): void {
            $now = new DateTimeImmutable();
            $r->setPublic()->setDate($now)->setExpires($now->modify('+5 minutes'));
        },
        null,
        'max-age=300, public',
    ],
]);

it('keeps a CDN-Cache-Control header the page set', function (): void {
    $response = ($this->respond)(fn() => new Response('page', headers: ['CDN-Cache-Control' => 'max-age=5']));

    expect($response->headers->get('CDN-Cache-Control'))->toBe('max-age=5');
});

it('sends no CDN-Cache-Control for responses a cache mustn’t store', function (Closure $policy): void {
    $response = ($this->respond)(function () use ($policy): Response {
        // A CDN header the page set is removed too.
        $response = new Response('page', headers: ['CDN-Cache-Control' => 'public, max-age=600']);
        $policy($this->app, $response);

        return $response;
    });

    expect($response->headers->has('CDN-Cache-Control'))->toBeFalse();
})->with([
    'noCache()' => fn($app) => $app->make(ResponseHeaders::class)->noCache(),
    'no-store' => fn($app, Response $r) => $r->headers->set('Cache-Control', 'public, no-store, max-age=60'),
    'no-cache' => fn($app, Response $r) => $r->headers->set('Cache-Control', 'public, no-cache, max-age=60'),
    'private' => fn($app, Response $r) => $r->setPrivate()->setMaxAge(60),
    'Set-Cookie' => fn($app, Response $r) => $r->headers->setCookie(new Cookie('preference', 'dark')),
    'queued cookie' => fn($app) => $app->make('cookie')->queue('preference', 'dark'),
    'Set-Cookie with a lifetime' => function ($app, Response $r): void {
        $app->make(ResponseHeaders::class)->setCache(600);
        $r->headers->setCookie(new Cookie('preference', 'dark'));
    },
]);

it('doesn’t give responses to Authorization requests the default', function (): void {
    $request = Request::create('/news');
    $request->headers->set('Authorization', 'Bearer x');

    $response = ($this->respond)(fn() => new Response('page'), $request);

    expect($response->headers->has('CDN-Cache-Control'))->toBeFalse();
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
        ->toBeFalse();
})->with([['POST', 200], ['GET', 404]]);

it('hashes tags the same way in the Cache-Tag header and in purges', function (): void {
    Http::fake();

    $response = ($this->respond)(function (): Response {
        $this->app->make(ElementCaches::class)->collectCacheTags(['element::42']);
        $this->app->make(ResponseCacheInfo::class)->addTags(['custom tag']);

        return new Response('page');
    });

    Event::dispatch(new ElementCachesInvalidated(['element::42', 'custom tag']));
    $this->app->terminate();

    // Short environment ID plus the CRC32 of each tag.
    $hashed = ['12345678fe708f7a', sprintf('12345678%x', crc32('custom tag'))];
    expect(explode(',', $response->headers->get('Cache-Tag')))
        ->toEqualCanonicalizing($hashed)
        ->and(purges())
        ->toBe([['https://gateway.craft.cloud/api/12345678-abcd/cache/purge', ['tags' => $hashed]]]);
});

it('bounds the Cache-Tag header by count and bytes with the overflow tag', function (int $count): void {
    $response = ($this->respond)(function () use ($count): Response {
        $this->app->make(ResponseCacheInfo::class)->addTags(array_map(strval(...), range(1, $count)));

        return new Response('page');
    });
    $tags = explode(',', $response->headers->get('Cache-Tag'));

    expect($tags[0])
        ->toBe('12345678-abcd:overflow')
        ->and(count($tags))
        ->toBeLessThanOrEqual(1000)
        ->and(strlen($response->headers->get('Cache-Tag')))
        ->toBeLessThanOrEqual(16_384);
})->with([1001, 2000]);

it('batches invalidations and URL purges into one signed gateway request', function (): void {
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

it('soft purges tags when the client is configured to', function (): void {
    $this->config->set('http-cache.clients.cloud', ['soft_purge' => true]);
    Http::fake();

    Event::dispatch(new ElementCachesInvalidated(['element::42']));
    Event::dispatch(new ElementDeleted(entry('news/gone')));
    $this->app->terminate();

    expect(purges())->toEqualCanonicalizing([
        ['https://gateway.craft.cloud/api/12345678-abcd/cache/invalidate', ['tags' => ['12345678fe708f7a']]],
        ['https://gateway.craft.cloud/api/12345678-abcd/cache/purge', ['tags' => ['12345678-abcd:uri:/news/gone']]],
    ]);
});

it('purges after each queue job', function (): void {
    Http::fake();

    Event::dispatch(new ElementCachesInvalidated(['element::42']));
    Event::dispatch(new JobProcessed('sync', test()->createStub(Illuminate\Contracts\Queue\Job::class)));
    $this->app->terminate();

    Http::assertSentCount(1);
});

it('reports gateway failures without failing termination', function (): void {
    $handler = new Monolog\Handler\TestHandler();
    $logger = new Monolog\Logger('test', [$handler]);
    $this->app->instance('log', $logger);
    $this->app->instance(Psr\Log\LoggerInterface::class, $logger);
    $this->app->singleton(
        Illuminate\Contracts\Debug\ExceptionHandler::class,
        Illuminate\Foundation\Exceptions\Handler::class,
    );
    Facade::clearResolvedInstance('log');
    Http::fake(['*' => Http::response('', 500)]);

    Event::dispatch(new ElementCachesInvalidated(['element']));
    $this->app->terminate();

    expect($handler->hasErrorThatContains('status code 500'))->toBeTrue();
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
    [
        'cloud:static-cache:purge-tags',
        ['tags' => ['a b', 'c']],
        ['tags' => [
            sprintf('12345678%x', crc32('a b')),
            sprintf('12345678%x', crc32('c')),
        ]],
    ],
    ['cloud:static-cache:purge-prefixes', ['prefixes' => ['example.com/blog']], ['prefixes' => ['example.com/blog']]],
]);
