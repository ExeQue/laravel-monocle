<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Lenses\RequestLens;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

covers(RequestLens::class);

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
            ->and($request['memory_peak_bytes'])->toBeGreaterThan(0)
            ->and($response->headers->get('X-Monocle-Id'))->toBe($capture->id)
            ->and($response->headers->get('Server-Timing'))->toContain('app;dur=');
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
