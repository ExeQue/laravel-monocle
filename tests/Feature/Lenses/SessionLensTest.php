<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\SessionLens;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;

covers(SessionLens::class, \ExeQue\Monocle\Lenses\Session\SnapshotsSession::class);

describe(SessionLens::class, function (): void {
    it('records the session contents with sensitive keys masked', function (): void {
        Route::middleware('web')->get('/session', function () {
            session(['cart' => ['items' => 2], 'api_token' => 'abcdefghijkl']);
            session()->flash('status', 'Saved');

            return 'ok';
        });

        $this->get('/session')->assertOk();

        $session = latestCapture()->section('session');

        expect($session['data']['cart'])->toBe(['items' => 2])
            ->and($session['data']['api_token'])->not->toBe('abcdefghijkl')
            ->and($session['data']['_token'])->not->toBe(session()->token())
            ->and($session['flash'])->toBe(['status'])
            ->and($session['id'])->toHaveLength(12)
            ->and($session['driver'])->toBe(config('session.driver'));
    });

    it('lists what the request added, changed and removed in the session', function (): void {
        Route::middleware('web')->get('/seed', function () {
            session(['cart' => ['items' => 1, 'coupon' => 'X'], 'theme' => 'dark']);

            return 'ok';
        });
        Route::middleware('web')->get('/change', function () {
            session()->put('cart.items', 3);
            session()->forget('cart.coupon');
            session()->put('seen', true);

            return 'ok';
        });

        $this->get('/seed')->assertOk();
        $this->get('/change')->assertOk();

        $changes = collect(latestCapture()->section('session')['changes'])->keyBy('field');

        expect($changes['cart.items'])->toBe(['field' => 'cart.items', 'from' => 1, 'to' => 3])
            ->and($changes['cart.coupon'])->toBe(['field' => 'cart.coupon', 'from' => 'X', 'to' => null])
            ->and($changes['seen'])->toBe(['field' => 'seen', 'from' => null, 'to' => true])
            ->and($changes)->not->toHaveKey('theme');
    });

    it('records nothing for requests without a session', function (): void {
        Route::get('/stateless', fn () => 'ok');

        $this->get('/stateless')->assertOk();

        expect(latestCapture()->section('session'))->toBe([]);
    });

    it('records nothing for a handled request outside a capture', function (): void {
        $request = Request::create('/outside');
        $request->setLaravelSession(app('session')->driver());

        event(new RequestHandled($request, new Response('ok')));

        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(Eyepiece::class)->finalize()->section('session'))->toBe([]);
    });

    it('records nothing when the session cannot be read', function (): void {
        $session = Mockery::mock(Session::class);
        $session->shouldReceive('all')->andThrow(new RuntimeException('session store down'));
        $request = Request::create('/broken');
        $request->setLaravelSession($session);

        app(Eyepiece::class)->start(CaptureType::Request);

        event(new RequestHandled($request, new Response('ok')));

        expect(app(Eyepiece::class)->finalize()->section('session'))->toBe([]);
    });

    it('describes itself and presents the session as a table', function (): void {
        $lens = app(SessionLens::class);

        expect($lens->description())->toContain('Session contents')
            ->and($lens->advice())->toContain('session state')
            ->and($lens->presentation()->tabView)->toBe('monocle::tabs.session');
    });
});
