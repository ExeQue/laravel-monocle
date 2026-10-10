<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Lenses\RequestLens;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

covers(ExeQue\Monocle\Lenses\Request\InertiaCapture::class, ExeQue\Monocle\Lenses\Request\JsonCapture::class, ExeQue\Monocle\Lenses\Request\TextCapture::class, ExeQue\Monocle\Lenses\Request\LivewireUpdateCapture::class, RequestLens::class);

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

        expect($request['response_capture'])->toBe(['capture' => ExeQue\Monocle\Lenses\Request\JsonCapture::class, 'cards' => [['title' => 'JSON', 'subtitle' => null, 'items' => ['ok' => true]]], 'raw' => '{"ok":true}'])
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
            'photo' => Illuminate\Http\UploadedFile::fake()->create('a.jpg', 3, 'image/jpeg'),
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

    it('keeps bodies only for the configured content types', function (): void {
        $lens = collect(app(ExeQue\Monocle\LensRegistry::class)->booted())->first(fn ($lens) => $lens instanceof RequestLens);
        Route::get('/html', fn () => '<p>hi</p>');
        Route::get('/problem', fn () => response('{"title":"x"}', 422, ['Content-Type' => 'application/problem+json; charset=utf-8']));

        $this->get('/html')->assertOk();
        expect(latestCapture()->section('request')['response_capture'])->toBeNull();

        $this->get('/problem')->assertStatus(422);
        expect(latestCapture()->section('request')['response_capture']['cards'][0]['items'])->toBe(['title' => 'x'])
            ->and(latestCapture()->section('request')['response_capture']['raw'])->toBe('{"title":"x"}');

        $lens->configure(['capture' => ['response_types' => ['text/*' => ExeQue\Monocle\Lenses\Request\TextCapture::class]]]);
        $this->get('/html')->assertOk();
        expect(latestCapture()->section('request')['response_capture']['cards'][0]['items'])->toBe(['body' => '<p>hi</p>']);

        $lens->configure(['capture' => ['response_types' => []]]);
        $this->get('/problem')->assertStatus(422);
        expect(latestCapture()->section('request')['response_capture'])->toBeNull();
    });

    it('shows a Livewire update as one card per component instead of the raw body', function (): void {
        Route::post('/livewire/update', fn () => response()->json(['components' => [[
            'snapshot' => json_encode(['data' => ['count' => 2, 'items' => [['a', 'b'], ['s' => 'arr']], 'password' => 'secret-value'], 'memo' => ['id' => 'abc', 'name' => 'counter']]),
            'effects' => ['html' => '<div>2</div>', 'dispatches' => [['name' => 'counted', 'params' => ['by' => 2]], ['name' => 'saved', 'params' => []]], 'returns' => [2]],
        ]]]))->name('default-livewire.update');

        $this->postJson('/livewire/update', ['components' => [[
            'snapshot' => json_encode(['data' => ['count' => 1, 'items' => [['a'], ['s' => 'arr']], 'password' => 'secret-value'], 'memo' => ['id' => 'abc', 'name' => 'counter']]),
            'updates' => ['step' => 1],
            'calls' => [['method' => 'increment', 'params' => [1], 'metadata' => []], ['method' => '__dispatch', 'params' => ['refresh', ['id' => 7]], 'metadata' => []]],
        ]]])->assertOk();

        $request = latestCapture()->section('request');
        $card = $request['response_capture']['cards'][0];

        expect($request['response_capture']['raw'])->toStartWith('{"components":')
            ->and($request['response_capture']['capture'])->toBe(ExeQue\Monocle\Lenses\Request\LivewireUpdateCapture::class)
            ->and($card['title'])->toBe('counter')
            ->and($card['subtitle'])->toBe('abc')
            ->and($card['items']['calls'])->toBe([['name' => 'increment', 'params' => [1]], ['name' => 'dispatch refresh', 'params' => ['id' => 7]]])
            ->and($card['items']['updates'])->toBe(['step' => 1])
            ->and($card['items']['changes'])->toBe([
                ['field' => 'count', 'from' => 1, 'to' => 2],
                ['field' => 'items.1', 'from' => null, 'to' => 'b'],
            ])
            ->and($card['items']['dispatched'])->toBe([['name' => 'counted', 'params' => ['by' => 2]], ['name' => 'saved', 'params' => null]])
            ->and($card['items']['returns'])->toBe([2])
            ->and($card['items']['html_bytes'])->toBe(12);
    });

    describe('Inertia', function (): void {
        $page = ['component' => 'Orders/Show', 'url' => '/orders/7', 'version' => 'abc', 'props' => ['order' => ['id' => 7], 'password' => 'secret-value'], 'deferredProps' => ['default' => ['stats']]];

        it('shows an Inertia visit as its component and props, with the partial reload asked for', function () use ($page): void {
            Route::get('/orders/7', fn () => response()->json($page, 200, ['X-Inertia' => 'true', 'Vary' => 'X-Inertia']));

            $this->getJson('/orders/7', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Orders/Show', 'X-Inertia-Partial-Data' => 'order, stats'])->assertOk();

            $capture = latestCapture()->section('request')['response_capture'];
            $card = $capture['cards'][0];

            expect($capture['capture'])->toBe(ExeQue\Monocle\Lenses\Request\InertiaCapture::class)
                ->and($card['title'])->toBe('Orders/Show')
                ->and($card['subtitle'])->toBe('/orders/7')
                ->and($card['items']['visit'])->toBe('inertia')
                ->and($card['items']['partial_reload'])->toBe(['component' => 'Orders/Show', 'only' => ['order', 'stats']])
                ->and($card['items']['props']['order'])->toBe(['id' => 7])
                ->and($card['items']['props']['password'])->not->toBe('secret-value')
                ->and($card['items']['deferred_props'])->toBe(['default' => ['stats']]);
        });

        it('reads the page from the HTML of a first visit and leaves other HTML alone', function () use ($page): void {
            Route::get('/attr', fn () => '<div id="app" data-page="'.htmlspecialchars(json_encode($page), ENT_QUOTES).'"></div>');
            Route::get('/script', fn () => '<script data-page="app" type="application/json">'.json_encode($page).'</script><div id="app"></div>');
            Route::get('/plain', fn () => '<p>hello</p>');

            $this->get('/attr')->assertOk();
            $attr = latestCapture()->section('request')['response_capture']['cards'][0];

            $this->get('/script')->assertOk();
            $script = latestCapture()->section('request')['response_capture']['cards'][0];

            $this->get('/plain')->assertOk();

            expect($attr['title'])->toBe('Orders/Show')
                ->and($attr['items']['visit'])->toBe('first load')
                ->and($script['title'])->toBe('Orders/Show')
                ->and(latestCapture()->section('request')['response_capture'])->toBeNull();
        });
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
