<?php

declare(strict_types=1);

use ExeQue\Monocle\Lenses\SessionLens;
use Illuminate\Support\Facades\Route;

covers(SessionLens::class);

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

    it('records nothing for requests without a session', function (): void {
        Route::get('/stateless', fn () => 'ok');

        $this->get('/stateless')->assertOk();

        expect(latestCapture()->section('session'))->toBe([]);
    });
});
