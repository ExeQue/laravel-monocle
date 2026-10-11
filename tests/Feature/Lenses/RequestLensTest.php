<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ArrayObject;
use Closure;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Facades\Monocle;
use ExeQue\Monocle\Lenses\RequestLens;
use ExeQue\Monocle\LensRegistry;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Folio\Events\ViewMatched;
use Laravel\Folio\MountPath;
use Laravel\Folio\Pipeline\MatchedView;
use Laravel\Octane\Events\RequestReceived;
use Mockery;
use RuntimeException;
use Tests\Fixtures\Http\ExplodingCapture;
use Tests\Fixtures\Http\LockedHeaderBag;
use Tests\Fixtures\Http\StaticCardCapture;
use Tests\Fixtures\Http\UnreadableResponse;
use Tests\Fixtures\Middleware\Abort;
use Tests\Fixtures\Models\Thing;
use Tests\TestCase;

covers(\ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture::class, \ExeQue\Monocle\Lenses\Request\JsonCapture::class, \ExeQue\Monocle\Lenses\Request\TextCapture::class, \ExeQue\Monocle\Lenses\Packages\Livewire\LivewireUpdateCapture::class, RequestLens::class);

describe(RequestLens::class, function (): void {
    beforeEach(function (): void {
        Route::get('/ping/{id}', fn (string $id) => response()->json(['pong' => $id]))->name('ping');
        Route::post('/login', fn () => response('ok'));
        Route::get('/_monocle/api', fn () => 'hidden');
    });

    it('records a request capture with phases, request facts and response headers', function (): void {
        $response = $this->get('/ping/7?token=abcdefgh1234&page=2', ['Authorization' => 'Bearer verysecrettoken', 'X-Monocle-Label' => 'baseline']);

        $response->assertOk();

        $capture = latestCapture();
        $phases = array_map(fn (Span $span): string => $span->name, $capture->spansOfKind('phase'));
        $request = $capture->section('request');

        expect($capture->name)->toBe('GET /ping/7')
            ->and($capture->status)->toBe('200')
            ->and($capture->label)->toBe('baseline')
            ->and($phases)->toContain('middleware (request)', 'routing', 'handle', 'terminate')
            ->and($request['route']['name'])->toBe('ping')
            ->and($request['route']['parameters'])->toBe(['id' => '7'])
            ->and($request['query']['token'])->toBe('abc****')
            ->and($request['query']['page'])->toBe('2')
            ->and($request['headers']['authorization'])->toBe('Bearer ver****')
            ->and($request['status'])->toBe(200)
            ->and($request['response_bytes'])->toBeGreaterThan(0)
            ->and($capture->health['memory_peak_bytes'])->toBeGreaterThan(0)
            ->and($response->headers->get('X-Monocle-Id'))->toBe($capture->id)
            ->and($response->headers->get('Server-Timing'))->toContain('app;dur=');
    });

    it('keeps text response bodies and leaves binary ones out', function (): void {
        Route::post('/json', fn () => response()->json(['ok' => true]));
        Route::get('/binary', fn () => response("\x89PNG", 200, ['Content-Type' => 'image/png']));

        $this->postJson('/json', ['name' => 'x', 'password' => 'secret-value'])->assertOk();
        $request = latestCapture()->section('request');

        expect($request['response_capture'])->toBe(['capture' => \ExeQue\Monocle\Lenses\Request\JsonCapture::class, 'cards' => [['title' => 'JSON', 'subtitle' => null, 'items' => ['ok' => true]]], 'raw' => '{"ok":true}'])
            ->and($request['content_type'])->toBe('application/json')
            ->and($request['input']['name'])->toBe('x')
            ->and($request['input']['password'])->not->toBe('secret-value');

        $this->get('/binary')->assertOk();

        expect(latestCapture()->section('request')['response_capture'])->toBeNull()
            ->and(latestCapture()->section('request')['response_bytes'])->toBe(4);
    });

    it('keeps multipart fields as input and files apart', function (): void {
        Route::post('/upload-form', fn () => 'ok');

        $this->post('/upload-form', [
            'title' => 'Holiday',
            'meta' => ['tags' => ['sea', 'sun']],
            'photo' => \Illuminate\Http\UploadedFile::fake()->create('a.jpg', 3, 'image/jpeg'),
        ])->assertOk();

        $request = latestCapture()->section('request');

        expect($request['input'])->toBe(['title' => 'Holiday', 'meta' => ['tags' => ['sea', 'sun']]])
            ->and($request['files'][0])->toMatchArray(['key' => 'photo', 'name' => 'a.jpg', 'mime' => 'image/jpeg']);
    });

    it('records decrypted cookies with the session cookie masked', function (): void {
        Route::middleware('web')->get('/cookies', fn () => 'ok');

        $this->withCookie('theme', 'dark')->withCookie(config('session.cookie'), 'session-id-value')->get('/cookies')->assertOk();

        $cookies = latestCapture()->section('request')['cookies'];

        expect($cookies['theme'])->toBe('dark')
            ->and($cookies[config('session.cookie')])->not->toBe('session-id-value');
    });

    it('keeps the raw value of cookies the application could not decrypt', function (): void {
        Route::middleware('web')->get('/cookies', fn () => 'ok');

        $this->withUnencryptedCookie('appearance', 'dark')
            ->withHeader('Cookie', 'appearance=dark')
            ->get('/cookies')
            ->assertOk();

        expect(latestCapture()->section('request')['cookies']['appearance'])->toBe('dark');
    });

    it('keeps bodies only for the configured content types', function (): void {
        $lens = collect(app(\ExeQue\Monocle\LensRegistry::class)->booted())->first(fn ($lens) => $lens instanceof RequestLens);
        Route::get('/html', fn () => '<p>hi</p>');
        Route::get('/problem', fn () => response('{"title":"x"}', 422, ['Content-Type' => 'application/problem+json; charset=utf-8']));

        $this->get('/html')->assertOk();
        expect(latestCapture()->section('request')['response_capture'])->toBeNull();

        $this->get('/problem')->assertStatus(422);
        expect(latestCapture()->section('request')['response_capture']['cards'][0]['items'])->toBe(['title' => 'x'])
            ->and(latestCapture()->section('request')['response_capture']['raw'])->toBe('{"title":"x"}');

        $lens->configure(['capture' => ['response_types' => ['text/*' => \ExeQue\Monocle\Lenses\Request\TextCapture::class]]]);
        $this->get('/html')->assertOk();
        expect(latestCapture()->section('request')['response_capture']['cards'][0]['items'])->toBe(['body' => '<p>hi</p>']);

        $lens->configure(['capture' => ['response_types' => []]]);
        $this->get('/problem')->assertStatus(422);
        expect(latestCapture()->section('request')['response_capture'])->toBeNull();

        // A capture for a package that is not installed is passed over for the next match.
        $lens->configure(['capture' => ['response_types' => ['text/html' => \Tests\Fixtures\MissingPackageCapture::class, 'text/*' => \ExeQue\Monocle\Lenses\Request\TextCapture::class]]]);
        $this->get('/html')->assertOk();
        expect(latestCapture()->section('request')['response_capture']['capture'])->toBe(\ExeQue\Monocle\Lenses\Request\TextCapture::class);
    });

    it('captures Livewire updates per component with the default options', function (): void {
        TestCase::$bootConfig = ['monocle.lenses.'.RequestLens::class => true];
        $this->refreshApplication();
        TestCase::$bootConfig = [];

        Route::post('/livewire/update', fn () => response()->json(['components' => [[
            'snapshot' => json_encode(['data' => ['count' => 2], 'memo' => ['id' => 'abc', 'name' => 'counter']]),
            'effects' => ['html' => '<div>2</div>'],
        ]]]))->name('default-livewire.update');

        $this->postJson('/livewire/update', ['components' => [[
            'snapshot' => json_encode(['data' => ['count' => 1], 'memo' => ['id' => 'abc', 'name' => 'counter']]),
            'updates' => [],
            'calls' => [],
        ]]])->assertOk();

        expect(latestCapture()->section('request')['response_capture']['capture'])->toBe(\ExeQue\Monocle\Lenses\Packages\Livewire\LivewireUpdateCapture::class);
    });

    it('shows a Livewire update as one card per component instead of the raw body', function (): void {
        Route::post('/livewire/update', fn () => response()->json(['components' => [[
            'snapshot' => json_encode(['data' => ['count' => 2, 'items' => [['a', 'b'], ['s' => 'arr']], 'log' => [['role' => 'assistant']], 'password' => 'secret-value'], 'memo' => ['id' => 'abc', 'name' => 'counter']]),
            'effects' => ['html' => '<div>2</div>', 'dispatches' => [['name' => 'counted', 'params' => ['by' => 2]], ['name' => 'saved', 'params' => []]], 'returns' => [2]],
        ]]]))->name('default-livewire.update');

        $this->postJson('/livewire/update', ['components' => [[
            'snapshot' => json_encode(['data' => ['count' => 1, 'items' => [['a'], ['s' => 'arr']], 'log' => [], 'password' => 'secret-value'], 'memo' => ['id' => 'abc', 'name' => 'counter']]),
            'updates' => ['step' => 1],
            'calls' => [['method' => 'increment', 'params' => [1], 'metadata' => []], ['method' => '__dispatch', 'params' => ['refresh', ['id' => 7]], 'metadata' => []]],
        ]]])->assertOk();

        $request = latestCapture()->section('request');
        $card = $request['response_capture']['cards'][0];

        expect($request['response_capture']['raw'])->toStartWith('{"components":')
            ->and($request['response_capture']['capture'])->toBe(\ExeQue\Monocle\Lenses\Packages\Livewire\LivewireUpdateCapture::class)
            ->and($card['title'])->toBe('counter')
            ->and($card['subtitle'])->toBe('abc')
            ->and($card['items']['calls'])->toBe([['name' => 'increment', 'params' => [1]], ['name' => 'dispatch refresh', 'params' => ['id' => 7]]])
            ->and($card['items']['updates'])->toBe(['step' => 1])
            ->and($card['items']['changes'])->toBe([
                ['field' => 'count', 'from' => 1, 'to' => 2],
                ['field' => 'items.1', 'from' => null, 'to' => 'b'],
                ['field' => 'log.0.role', 'from' => null, 'to' => 'assistant'],
            ])
            ->and($card['items']['dispatched'])->toBe([['name' => 'counted', 'params' => ['by' => 2]], ['name' => 'saved', 'params' => null]])
            ->and($card['items']['returns'])->toBe([2])
            ->and($card['items']['html_bytes'])->toBe(12);
    });

    describe('Inertia', function (): void {
        beforeEach(function (): void {
            \Illuminate\Support\Facades\View::addLocation(dirname(__DIR__, 2).'/Fixtures/views');
            \Inertia\Inertia::setRootView('inertia-app');
            \Inertia\Inertia::version('abc');

            Route::get('/orders/7', fn () => \Inertia\Inertia::render('Orders/Show', [
                'order' => ['id' => 7],
                'customer' => fn () => ['name' => 'Ada'],
                'password' => 'secret-value',
                'stats' => \Inertia\Inertia::defer(fn () => ['revenue' => 10]),
            ]));
        });

        it('shows the page of an Inertia visit, with the partial reload asked for', function (): void {
            $this->get('/orders/7', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'abc', 'X-Inertia-Partial-Component' => 'Orders/Show', 'X-Inertia-Partial-Data' => 'order,customer'])->assertOk();

            $capture = latestCapture()->section('request')['response_capture'];
            $card = $capture['cards'][0];

            expect($capture['capture'])->toBe(\ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture::class)
                ->and($card['title'])->toBe('Orders/Show')
                ->and($card['subtitle'])->toBe('/orders/7')
                ->and($card['items']['visit'])->toBe('inertia')
                ->and($card['items']['version'])->toBe('abc')
                ->and($card['items']['partial_reload'])->toBe(['component' => 'Orders/Show', 'only' => ['order', 'customer']])
                ->and($card['items']['props'])->toHaveKeys(['order', 'customer'])
                ->and($card['items']['props'])->not->toHaveKey('password');
        });

        it('reads the page from the HTML of a first visit, masking secrets, and leaves other HTML alone', function (): void {
            Route::get('/plain', fn () => '<p>hello</p>');

            $this->get('/orders/7')->assertOk();
            $card = latestCapture()->section('request')['response_capture']['cards'][0];

            $this->get('/plain')->assertOk();

            expect($card['title'])->toBe('Orders/Show')
                ->and($card['items']['visit'])->toBe('first load')
                ->and($card['items']['props']['order'])->toBe(['id' => 7])
                ->and($card['items']['props']['password'])->not->toBe('secret-value')
                ->and($card['items']['deferred_props'])->toBe(['default' => ['stats']])
                ->and(latestCapture()->section('request')['response_capture'])->toBeNull();
        });
    });

    it('uses the matched Folio page as the action', function (): void {
        $pages = base_path('resources/views/monocle-folio-'.bin2hex(random_bytes(3)));
        mkdir($pages.'/users', 0775, true);
        file_put_contents($pages.'/users/[id].blade.php', "<?php\nuse function Laravel\\Folio\\name;\nname('users.show');\n?>\nUser {{ \$id }}\n");

        \Laravel\Folio\Folio::path($pages);

        try {
            $this->get('/users/4')->assertOk()->assertSee('User 4');
        } finally {
            exec('rm -rf '.escapeshellarg($pages));
        }

        $request = latestCapture()->section('request');
        $page = substr($pages, strlen(base_path()) + 1).'/users/[id].blade.php';

        expect($request['route']['action'])->toBe($page)
            ->and($request['folio'])->toMatchArray([
                'page' => $page,
                'name' => 'users.show',
                'mount_path' => $pages,
                'parameters' => ['id' => '4'],
            ]);
    });

    it('starts an Octane request before global middleware runs', function (): void {
        app(\ExeQue\Monocle\Eyepiece::class)->reset();
        $request = \Illuminate\Http\Request::create('/octane');

        event(new \Laravel\Octane\Events\RequestReceived(app(), app(), $request));

        $capture = app(\ExeQue\Monocle\Eyepiece::class)->active();

        expect($capture?->type)->toBe(\ExeQue\Monocle\Enums\CaptureType::Request)
            ->and(array_column(array_map(fn ($span) => $span->toArray(), $capture->spans), 'name'))->toBe(['middleware (request)']);
    });

    it('masks matching input keys', function (): void {
        $this->post('/login', ['email' => 'a@b.c', 'password' => 'hunter22hunter22']);

        expect(latestCapture()->section('request')['input'])->toBe(['email' => 'a@b.c', 'password' => 'hunt****']);
    });

    it('derives a controller span', function (): void {
        $this->get('/ping/1');

        $controller = latestCapture()->spans['controller'] ?? null;

        expect($controller)->not->toBeNull()
            ->and($controller->kind)->toBe('controller')
            ->and($controller->duration())->toBeGreaterThanOrEqual(0);
    });

    it('places spans opened in the controller under the controller span', function (): void {
        Route::get('/spans', function () {
            Monocle::measure('load author', fn () => usleep(1000));

            return 'ok';
        });

        $this->get('/spans');

        $capture = latestCapture();
        $span = collect($capture->spans)->firstWhere('name', 'load author');

        expect($span->parentId)->toBe('controller');
    });

    it('does not record excepted paths', function (): void {
        $this->get('/_monocle/api')->assertOk();

        expect(latestCapture())->toBeNull();
    });

    it('leaves package dashboards and asset routes out but keeps Livewire updates', function (): void {
        foreach (['telescope/requests', 'pulse', 'up', 'livewire-abc123/livewire.min.js', 'livewire-abc123/js/counter.js', 'flux/flux.js', '_debugbar/assets/js'] as $path) {
            Route::get($path, fn () => 'x');
            $this->get('/'.$path)->assertOk();
        }

        expect(latestCapture())->toBeNull();

        Route::post('livewire-abc123/update', fn () => 'ok');
        $this->post('/livewire-abc123/update')->assertOk();

        expect(latestCapture()?->name)->toBe('POST /livewire-abc123/update');
    });

    it('does not record Livewire updates made only by excepted components', function (): void {
        Route::post('livewire/update', fn () => 'ok');
        $component = fn (string $name): array => ['snapshot' => json_encode(['data' => [], 'memo' => ['id' => 'x', 'name' => $name]]), 'updates' => [], 'calls' => []];

        $this->postJson('/livewire/update', ['components' => [$component('pulse.usage'), $component('pulse.queues')]])->assertOk();

        expect(latestCapture())->toBeNull();

        $this->postJson('/livewire/update', ['components' => [$component('pulse.usage'), $component('cart')]])->assertOk();

        expect(latestCapture()?->name)->toBe('POST /livewire/update');
    });

    it('does not record broadcast channel authorization by default', function (): void {
        Route::post('/broadcasting/auth', fn () => 'ok');

        $this->post('/broadcasting/auth')->assertOk();

        expect(latestCapture())->toBeNull();
    });

    it('does not record Boost browser log uploads by default', function (): void {
        Route::post('/_boost/browser-logs', fn () => 'ok');

        $this->post('/_boost/browser-logs')->assertOk();

        expect(latestCapture())->toBeNull();
    });

    it('records uploads, authenticated users, redirects and streamed responses', function (): void {
        Route::post('/upload', fn () => redirect('/done'));
        Route::get('/stream', fn () => response()->stream(function (): void {
            echo 'chunk';
        }));
        Route::get('/me', fn () => auth()->user()?->getAuthIdentifier())->middleware('auth');

        $this->post('/upload', ['photo' => UploadedFile::fake()->create('a.jpg', 12, 'image/jpeg')])->assertRedirect('/done');
        $upload = latestCapture()->section('request');

        expect($upload['files'])->toBe([['key' => 'photo', 'name' => 'a.jpg', 'size' => 12288, 'mime' => 'image/jpeg']])
            ->and($upload['redirect'])->toContain('/done')
            ->and($upload['status'])->toBe(302);

        $this->get('/stream')->assertOk();

        expect(latestCapture()->section('request')['response_bytes'])->toBeNull();

        $user = new GenericUser(['id' => 9]);
        $this->actingAs($user)->get('/me')->assertOk();

        expect(latestCapture()->section('request')['auth'])->toBe(['class' => GenericUser::class, 'id' => 9]);
    });

    it('records one capture per request in the same process', function (): void {
        $this->get('/ping/1');
        $this->get('/ping/2');

        expect(app(CaptureReader::class)->list(Filter::fromArray([]))['total'])->toBe(2)
            ->and(latestCapture()->name)->toBe('GET /ping/2');
    });

    /**
     * Run a request through the lens by its events, without the HTTP kernel.
     *
     * @param  Closure(Request, RoutingRoute): void|null  $between  runs after the route matched
     * @param  array<string, mixed>  $parameters  route parameters as bound by the time the route matched
     */
    $handle = function (?RoutingRoute $route = null, ?Closure $between = null, bool $prepare = true, array $parameters = []): ?Capture {
        $route ??= new RoutingRoute(['GET'], '/manual', fn () => 'ok');
        $request = Request::create('/'.$route->uri());
        $route->bind($request);

        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(fn () => $route);

        event(new Routing($request));
        event(new RouteMatched($route, $request));

        if ($between !== null) {
            $between($request, $route);
        }

        if ($prepare) {
            event(new PreparingResponse($request, 'ok'));
        }

        return app(Eyepiece::class)->finalize();
    };

    $booted = fn (): RequestLens => collect(app(LensRegistry::class)->booted())->first(fn (object $lens): bool => $lens instanceof RequestLens);

    $span = function (Capture $capture, string $id, string $kind, float $start, float $end, array $meta = [], ?string $parentId = null): Span {
        $span = new Span($id, $id, $kind, $parentId, null, null);
        $span->start = $start;
        $span->end = $end;
        $span->meta = $meta;
        $capture->spans[$id] = $span;

        return $span;
    };

    it('opens the bootstrap phase at the start of a capture it starts while the application boots', function (): void {
        app(Eyepiece::class)->reset();
        $terminating = null;

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturnFalse();
        $app->shouldReceive('booted')->andReturnUsing(fn (Closure $callback) => $callback());
        $app->shouldReceive('terminating')->andReturnUsing(function (Closure $callback) use (&$terminating): void {
            $terminating = $callback;
        });

        new RequestLens(app(Eyepiece::class))->boot($app);

        $phases = app(Eyepiece::class)->active()->spansOfKind('phase');

        expect(array_map(fn (Span $span): string => $span->name, $phases))->toBe(['bootstrap', 'middleware (request)'])
            ->and($phases[0]->start)->toBe(0.0)
            ->and($phases[0]->isOpen())->toBeFalse();

        $terminating();

        expect(app(Eyepiece::class)->active())->toBeNull()
            ->and(latestCapture()?->type)->toBe(CaptureType::Request);

        $terminating();

        expect(app(Eyepiece::class)->active())->toBeNull();
    });

    it('keeps the running capture when Octane hands it a request', function (): void {
        $capture = app(Eyepiece::class)->start(CaptureType::Request);

        event(new RequestReceived(app(), app(), Request::create('/octane')));

        expect(app(Eyepiece::class)->active())->toBe($capture);
    });

    it('ignores Folio pages and handled requests outside a recording capture', function (): void {
        $folio = new ViewMatched(new MatchedView(base_path('page.blade.php'), []), new MountPath(base_path(), '/', [], null));
        $handled = new RequestHandled(Request::create('/x'), new Response('ok'));

        app(Eyepiece::class)->reset();
        event($folio);
        event($handled);

        app(Eyepiece::class)->start(CaptureType::Request);
        app(Eyepiece::class)->discard();
        event($folio);
        event($handled);

        expect(app(Eyepiece::class)->active()->sections)->toBe([])
            ->and($handled->response->headers->has('X-Monocle-Id'))->toBeFalse();
    });

    it('opens no phase without a capture', function (): void {
        app(Eyepiece::class)->reset();
        $lens = app(RequestLens::class);

        (fn () => $this->phase('routing'))->call($lens);

        expect(app(Eyepiece::class)->active())->toBeNull();
    });

    it('carries on when the response refuses the Monocle headers', function (): void {
        Route::get('/locked', function () {
            $response = new Response('ok');
            $response->headers = new LockedHeaderBag($response->headers->all());

            return $response;
        });

        $response = $this->get('/locked')->assertOk();

        expect($response->headers->has('X-Monocle-Id'))->toBeFalse()
            ->and(latestCapture()->section('request')['status'])->toBe(200);
    });

    it('records Livewire updates when no component is excepted or there are no components', function (): void {
        Route::post('livewire/update', fn () => 'ok');
        $component = ['snapshot' => json_encode(['data' => [], 'memo' => ['id' => 'x', 'name' => 'pulse.usage']]), 'updates' => [], 'calls' => []];

        $this->postJson('/livewire/update', ['components' => []])->assertOk();

        expect(latestCapture()?->name)->toBe('POST /livewire/update');

        config(['monocle.except.livewire' => []]);
        $this->postJson('/livewire/update', ['components' => [$component]])->assertOk();

        expect(app(CaptureReader::class)->list(Filter::fromArray([]))['total'])->toBe(2);
    });

    it('describes bound models as Class#key and other objects by their class', function () use ($handle): void {
        $capture = $handle(route: new RoutingRoute(['GET'], '/things', fn () => 'ok'), parameters: [
            'thing' => new Thing()->forceFill(['id' => 5]),
            'options' => new ArrayObject(['compact']),
        ]);

        expect($capture->section('request')['route']['parameters'])->toBe(['thing' => Thing::class.'#5', 'options' => ArrayObject::class]);
    });

    it('records responses whose body cannot be read', function () use ($booted): void {
        $booted()->configure(['capture' => ['headers' => ['X-Card' => StaticCardCapture::class]]]);
        app(Eyepiece::class)->start(CaptureType::Request);

        event(new RequestHandled(Request::create('/unreadable'), new UnreadableResponse('', 200, ['X-Card' => '1'])));

        $request = app(Eyepiece::class)->finalize()->section('request');

        expect($request['response_bytes'])->toBeNull()
            ->and($request['response_capture'])->toBe(['capture' => StaticCardCapture::class, 'cards' => [['title' => 'Static', 'items' => ['status' => 200]]], 'raw' => null]);
    });

    it('records the failure of a response capture and keeps the request', function () use ($booted): void {
        config(['monocle.throw_failures' => false]);
        $booted()->configure(['capture' => ['headers' => ['X-Card' => ExplodingCapture::class]]]);
        app(Eyepiece::class)->start(CaptureType::Request);

        event(new RequestHandled(Request::create('/exploding'), new Response('ok', 200, ['X-Card' => '1'])));

        $capture = app(Eyepiece::class)->finalize();

        expect($capture->section('request')['response_capture'])->toBeNull()
            ->and($capture->section('request')['status'])->toBe(200)
            ->and(array_column($capture->health['failures'], 'message'))->toBe(['capture exploded']);
    });

    it('captures nothing for a response without a content type', function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        event(new RequestHandled(Request::create('/bare'), new Response('ok')));

        $request = app(Eyepiece::class)->finalize()->section('request');

        expect($request['content_type'])->toBeNull()
            ->and($request['response_capture'])->toBeNull();
    });

    it('records no user when resolving the user fails', function (): void {
        $request = Request::create('/guarded');
        $request->setUserResolver(fn () => throw new RuntimeException('auth down'));

        event(new Routing($request));

        expect(app(Eyepiece::class)->finalize()->section('request')['auth'])->toBeNull();
    });

    it('adds the database time to the Server-Timing header', function (): void {
        Route::get('/db', fn () => DB::select('select 1') ? 'ok' : 'no');

        $response = $this->get('/db')->assertOk();

        expect(latestCapture()->rows('queries'))->not->toBeEmpty()
            ->and($response->headers->get('Server-Timing'))->toContain('db;dur=');
    });

    it('derives no controller span when no route matched or a route middleware short-circuited', function (): void {
        Route::get('/aborted', fn () => 'never')->middleware(Abort::class);

        $this->get('/missing')->assertNotFound();

        expect(latestCapture()->spans)->not->toHaveKey('controller');

        $this->get('/aborted')->assertStatus(418);

        expect(latestCapture()->spans)->not->toHaveKey('controller');
    });

    it('derives no controller span before the controller finished', function () use ($handle, $span): void {
        $capture = $handle(prepare: false);

        expect($capture->spans)->not->toHaveKey('controller');

        $capture = $handle(between: function () use ($span): void {
            $capture = app(Eyepiece::class)->active();
            $now = app(Eyepiece::class)->now();
            $span($capture, 'late', 'middleware.request', $now + 50, $now + 60, ['passed' => true, 'group' => 'route']);
        });

        expect($capture->spans)->not->toHaveKey('controller');
    });

    it('ends the controller at the first response middleware and skips middleware that did not hand on', function () use ($handle, $span): void {
        $capture = $handle(prepare: false, between: function () use ($span): void {
            $capture = app(Eyepiece::class)->active();
            $now = app(Eyepiece::class)->now();
            $handlePhase = collect($capture->spansOfKind('phase'))->firstWhere('name', 'handle');

            $span($capture, 'blocked', 'middleware.request', $now, $now + 1, ['passed' => false, 'group' => 'global']);
            $span($capture, 'outer', 'middleware.response', $now + 30, $now + 31);
            $span($capture, 'inner', 'middleware.response', $now + 20, $now + 21);
            // Before the route matched, however long the request took to get here.
            $span($capture, 'before', 'user', $handlePhase->start - 2, $handlePhase->start - 1, parentId: $handlePhase->id);
            $span($capture, 'inside', 'user', $now + 5, $now + 6, parentId: $handlePhase->id);
        });

        $controller = $capture->spans['controller'];

        expect($controller->end)->toBe($capture->spans['inner']->start)
            ->and($capture->spans['inside']->parentId)->toBe('controller')
            ->and($capture->spans['before']->parentId)->not->toBe('controller');
    });

    it('roots the controller span when there is no handle phase', function () use ($handle): void {
        $capture = $handle(between: function (): void {
            $capture = app(Eyepiece::class)->active();

            foreach ($capture->spansOfKind('phase') as $phase) {
                if ($phase->name === 'handle') {
                    unset($capture->spans[$phase->id]);
                }
            }
        });

        expect($capture->spans['controller']->parentId)->toBeNull();
    });

    it('presents the request with the auth section', function (): void {
        $presentation = app(RequestLens::class)->presentation();

        expect($presentation->tabView)->toBe('monocle::tabs.request')
            ->and($presentation->includes)->toBe(['auth']);
    });
});
