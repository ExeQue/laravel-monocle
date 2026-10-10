<?php

declare(strict_types=1);

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Lenses\RequestLens;
use Illuminate\Support\Facades\Route;

covers(RequestLens::class);

describe(RequestLens::class, function (): void {
    beforeEach(function (): void {
        Route::get('/ping/{id}', fn (string $id) => response()->json(['pong' => $id]))->name('ping');
        Route::post('/login', fn () => response('ok'));
        Route::get('/_monocle/api', fn () => 'hidden');
    });

    it('records a request entry with phases, request facts and response headers', function (): void {
        $response = $this->get('/ping/7?token=abcdefgh1234&page=2', ['Authorization' => 'Bearer verysecrettoken', 'X-Monocle-Label' => 'baseline']);

        $response->assertOk();

        $entry = latestEntry();
        $phases = array_map(fn (Span $span): string => $span->name, $entry->spansOfKind('phase'));
        $request = $entry->section('request');

        expect($entry->name)->toBe('GET /ping/7')
            ->and($entry->status)->toBe('200')
            ->and($entry->label)->toBe('baseline')
            ->and($phases)->toContain('middleware', 'routing', 'handle', 'terminate')
            ->and($request['route']['name'])->toBe('ping')
            ->and($request['route']['parameters'])->toBe(['id' => '7'])
            ->and($request['query']['token'])->toBe('abc****')
            ->and($request['query']['page'])->toBe('2')
            ->and($request['headers']['authorization'])->toBe('Bear****')
            ->and($request['status'])->toBe(200)
            ->and($request['response_bytes'])->toBeGreaterThan(0)
            ->and($request['memory_peak_bytes'])->toBeGreaterThan(0)
            ->and($response->headers->get('X-Monocle-Id'))->toBe($entry->id)
            ->and($response->headers->get('Server-Timing'))->toContain('app;dur=');
    });

    it('masks matching input keys', function (): void {
        $this->post('/login', ['email' => 'a@b.c', 'password' => 'hunter22hunter22']);

        expect(latestEntry()->section('request')['input'])->toBe(['email' => 'a@b.c', 'password' => 'hunt****']);
    });

    it('derives a controller span', function (): void {
        $this->get('/ping/1');

        $controller = latestEntry()->spans['controller'] ?? null;

        expect($controller)->not->toBeNull()
            ->and($controller->kind)->toBe('controller')
            ->and($controller->duration())->toBeGreaterThanOrEqual(0);
    });

    it('does not record excepted paths', function (): void {
        $this->get('/_monocle/api')->assertOk();

        expect(latestEntry())->toBeNull();
    });

    it('records one entry per request in the same process', function (): void {
        $this->get('/ping/1');
        $this->get('/ping/2');

        expect(app(ExeQue\Monocle\Reader\EntryReader::class)->list(ExeQue\Monocle\Reader\Filter::fromArray([]))['total'])->toBe(2)
            ->and(latestEntry()->name)->toBe('GET /ping/2');
    });
});
