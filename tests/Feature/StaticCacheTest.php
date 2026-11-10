<?php

declare(strict_types=1);

use craft\cloud\CloudServiceProvider;
use craft\cloud\signing\RequestSigner;
use craft\cloud\staticcache\CacheResponse;
use craft\cloud\staticcache\PurgeCommand;
use craft\cloud\staticcache\PurgeStaticCache;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Element\ElementCaches;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Http\Middleware\HandleTokenRequest;
use CraftCms\Cms\Http\Middleware\ResolveSite;
use CraftCms\Cms\Http\Middleware\SetHeaders;
use CraftCms\Cms\Http\Mixins\RequestMixin;
use CraftCms\Cms\Http\ResponseHeaders;
use CraftCms\Cms\Route\MatchedElement;
use CraftCms\Cms\Utility\Utilities\ClearCaches;
use CraftCms\DependencyAwareCache\Dependency\TagDependency;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Verifier;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Cookie\CookieJar;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
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
    $this->app->register(BusServiceProvider::class);
    Facade::clearResolvedInstances();
    Request::mixin(new RequestMixin());
    $this->config->set('session.cookie', 'craft_session');
    $this->router = $this->app->make(Router::class);
    $this->app->instance(KernelContract::class, new Kernel($this->app, $this->router));
    // The groups core defines before Cloud's provider boots, with the middleware that sets or removes headers.
    $this->router->middlewareGroup('web', [EncryptCookies::class, AddQueuedCookiesToResponse::class]);
    $this->router->middlewareGroup('craft', [SetHeaders::class]);
    $this->router->middlewareGroup('craft.web', []);
    $this->server('CRAFT_CLOUD', '1');
    $this->server('CRAFT_CLOUD_ENVIRONMENT_ID', '12345678-abcd');
    $this->server('CRAFT_CLOUD_SIGNING_KEY', 'test-key');
    $this->app->register(CloudServiceProvider::class);
    $this->app->boot();
    Bus::fake();
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

function entry(): Entry
{
    $entry = test()->createStub(Entry::class);
    $entry->id = 42;
    $entry->method('getRoute')->willReturn('templates/render');

    return $entry;
}

function purgedTags(): array
{
    $tags = [];
    Bus::assertDispatched(PurgeStaticCache::class, function (PurgeStaticCache $job) use (&$tags): bool {
        $tags = $job->tags;

        return true;
    });

    return $tags;
}

it('runs outside core and cookie middleware on site routes', function (array $groups, array $expected): void {
    $route = $this->router->get('/news', fn() => 'news')->middleware($groups);

    expect($this->router->gatherRouteMiddleware($route))->toBe($expected);
})->with([
    'web' => [
        ['web', 'craft', 'craft.web'],
        [CacheResponse::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, SetHeaders::class],
    ],
    'without cookies' => [
        ['craft',              'craft.web'],
        [CacheResponse::class, SetHeaders::class],
    ],
]);

it('caches responses without a policy for a year, capped at element expiry', function (?int $expiry): void {
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

it('follows the response’s own lifetime, preferring s-maxage', function (
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
]);

it('leaves an explicit CDN-Cache-Control header alone', function (): void {
    $response = ($this->respond)(fn() => new Response('page', headers: ['CDN-Cache-Control' => 'max-age=5']));

    expect($response->headers->get('CDN-Cache-Control'))->toBe('max-age=5');
});

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

it('caches requests with unrelated cookies, without tags when the page has none', function (): void {
    $response = ($this->respond)(fn() => new Response('page'), Request::create('/news', cookies: ['analytics' => 'x']));

    expect($response->headers->get('CDN-Cache-Control'))
        ->toStartWith('max-age=31536000')
        ->and($response->headers->has('Cache-Tag'))
        ->toBeFalse();
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

it('tags responses with the collected and matched element tags', function (): void {
    $entry = entry();
    $response = ($this->respond)(function () use ($entry): Response {
        MatchedElement::set($entry);
        $this->app->make(ElementCaches::class)->collectCacheTags(['news', 'element::42']);

        return new Response('page');
    });

    // The first 8 characters of the environment ID, plus the hex CRC32 of `news`, `element::42`, `element`, and
    // `element::{class}`.
    expect(explode(',', $response->headers->get('Cache-Tag')))->toBe([
        '123456781dd39950',
        '12345678fe708f7a',
        '1234567841405e39',
        '12345678' . hash('crc32b', 'element::' . $entry::class),
    ]);
});

it('adds the overflow tag when tags exceed Cloudflare’s limits', function (int $count): void {
    $response = ($this->respond)(function () use ($count): Response {
        $this->app->make(ElementCaches::class)->collectCacheTags(array_map(strval(...), range(1, end: $count)));

        return new Response('page');
    });
    $tags = explode(',', $response->headers->get('Cache-Tag'));

    expect($tags[0])
        ->toBe('12345678-abcd:overflow')
        ->and(count($tags))
        ->toBeLessThanOrEqual(1000)
        ->and(strlen($response->headers->get('Cache-Tag')))
        ->toBeLessThanOrEqual(16_384)
        ->and(array_slice($tags, offset: 1))
        ->each->toMatch('/^12345678[0-9a-f]{1,8}$/');
})->with([1001, 5000]);

it('queues one purge per request for every invalidated tag except drafts and revisions', function (): void {
    TagDependency::invalidate(['news']);
    $this->app->make(ElementCaches::class)->invalidateAll();
    TagDependency::invalidate([
        'element::42',
        'element::' . Entry::class . '::*',
        'element::' . Entry::class . '::drafts',
    ]);
    TagDependency::invalidate(['element::43', 'element::' . Entry::class . '::revisions']);
    TagDependency::invalidate(['element-search-query::' . Entry::class, 'element-index-query::' . Entry::class]);
    Bus::assertNothingDispatched();

    $this->app->terminate();

    Bus::assertDispatchedTimes(PurgeStaticCache::class, 1);
    expect(purgedTags())->toBe(['123456781dd39950', '1234567841405e39', '12345678-abcd:overflow']);
});

it('queues a purge after each queue job', function (): void {
    TagDependency::invalidate(['news']);
    Illuminate\Support\Facades\Event::dispatch(
        new JobProcessed('sync', test()->createStub(Illuminate\Contracts\Queue\Job::class)),
    );

    Bus::assertDispatchedTimes(PurgeStaticCache::class, 1);
    $this->app->terminate();
    Bus::assertDispatchedTimes(PurgeStaticCache::class, 1);
});

it('sends purges to the gateway with a signed request', function (array $tags, array $prefixes, array $payload): void {
    $this->server('CRAFT_CLOUD_GATEWAY_BASE_URL', 'https://gateway.example/');
    Http::fake(['*' => Http::response('', 204)]);

    new PurgeStaticCache($tags, $prefixes)->handle($this->app->make(RequestSigner::class));

    Http::assertSent(function (ClientRequest $request) use ($payload): bool {
        expect($request->url())
            ->toBe('https://gateway.example/api/12345678-abcd/cache/purge')
            ->and($request->data())
            ->toBe($payload)
            ->and(new Verifier(new HmacSha256('test-key'))->verify($request->toPsrRequest()))
            ->toBeTrue();

        return true;
    });
})->with([
    [['a', 'b'], [], ['tags' => ['a', 'b']]],
    [[], ['example.com/blog'], ['prefixes' => ['example.com/blog']]],
]);

it('fails the job when the gateway rejects a purge, so the queue retries it', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    new PurgeStaticCache(['a'])->handle($this->app->make(RequestSigner::class));
})->throws(RequestException::class);

it('purges everything from the Caches utility', function (): void {
    $option = collect(ClearCaches::cacheOptions())->firstWhere('key', 'cloud-static-cache');

    $option['action']();

    Bus::assertDispatchedSync(
        PurgeStaticCache::class,
        fn(PurgeStaticCache $job): bool => (
            $job->tags === [
                '12345678-abcd:uri',
                '12345678-abcd:cdn',
                '12345678-abcd:rasterize',
            ]
        ),
    );
});

it('purges from the console', function (array $options, array $tags, array $prefixes): void {
    $command = new PurgeCommand();
    $command->setLaravel($this->app);

    expect($command->run(new ArrayInput($options), new BufferedOutput()))->toBe(0);
    Bus::assertDispatchedSync(
        PurgeStaticCache::class,
        fn(PurgeStaticCache $job): bool => $job->tags === $tags && $job->prefixes === $prefixes,
    );
})->with([
    'everything' => [[], ['12345678-abcd:uri', '12345678-abcd:cdn', '12345678-abcd:rasterize'], []],
    'tags' => [['--tag' => ['a', 'b']], ['a', 'b'], []],
    'prefixes' => [['--prefix' => ['example.com/blog']], [], ['example.com/blog']],
]);
