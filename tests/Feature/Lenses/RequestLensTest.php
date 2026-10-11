<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Lenses\RequestLens;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

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
            ->and($request['headers']['authorization'])->toBe('Bear****')
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
});
