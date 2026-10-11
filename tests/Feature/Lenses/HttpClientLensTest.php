<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\HttpClientLens;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

covers(HttpClientLens::class);

describe(HttpClientLens::class, function (): void {
    beforeEach(function (): void {
        Http::fake([
            'api.example.com/*' => Http::response(['ok' => true], 201, ['X-Rate' => '10']),
            'down.example.com/*' => Http::response('nope', 503),
        ]);

        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('records calls with masked query and headers, sizes and status', function (): void {
        Http::withToken('verysecrettoken')->post('https://api.example.com/orders?api_token=abcdefghij&page=2', ['name' => 'ada']);
        Http::get('https://down.example.com/health');

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('http');

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['method'])->toBe('POST')
            ->and($rows[0]['url'])->toBe('https://api.example.com/orders?api_token=ab****&page=2')
            ->and($rows[0]['request_headers']['Authorization'])->toBe('Bearer ver****')
            ->and($rows[0]['request_bytes'])->toBeGreaterThan(0)
            ->and($rows[0]['request_body'])->toBeNull()
            ->and($rows[0]['status'])->toBe(201)
            ->and($rows[0]['response_headers']['X-Rate'])->toBe('10')
            ->and($rows[0]['response_bytes'])->toBe(strlen('{"ok":true}'))
            ->and($rows[0]['failed'])->toBeFalse()
            ->and($rows[0]['duration'])->toBeNumeric()
            ->and($rows[1]['status'])->toBe(503)
            ->and($rows[1]['failed'])->toBeTrue()
            ->and($rows[0]['origin']['file'])->toContain('HttpClientLensTest.php');

        $summary = app(HttpClientLens::class)->summarize($capture);

        expect($summary['totals'])->toMatchArray(['http_calls' => 2, 'http_failed' => 1, 'http_by_host' => ['api.example.com' => 1, 'down.example.com' => 1]])
            ->and($summary['warnings'])->toBe(['HTTP call failed: GET https://down.example.com/health (503)']);
    });

    it('records connection failures', function (): void {
        $request = new Request(new PsrRequest('GET', 'https://gone.example.com/'));

        event(new ConnectionFailed($request, new ConnectionException('Could not resolve host')));

        $rows = app(Eyepiece::class)->finalize()->rows('http');

        expect($rows[0])->toMatchArray(['method' => 'GET', 'url' => 'https://gone.example.com/', 'status' => null, 'failed' => true])
            ->and($rows[0]['exception']['message'])->toBe('Could not resolve host');
    });

    it('includes bodies when configured', function (): void {
        $lens = app(HttpClientLens::class);
        $lens->configure(['bodies' => true]);
        $lens->boot($this->app, app(Eyepiece::class));

        Http::post('https://api.example.com/orders', ['name' => 'ada']);

        $rows = app(Eyepiece::class)->finalize()->rows('http');
        $withBody = collect($rows)->first(fn (array $row): bool => $row['request_body'] !== null);

        expect($withBody['request_body'])->toBe('{"name":"ada"}')
            ->and($withBody['response_body'])->toBe('{"ok":true}');
    });

    it('summarizes nothing without calls', function (): void {
        expect(app(HttpClientLens::class)->summarize(app(Eyepiece::class)->finalize()))->toBe([]);
    });
});
