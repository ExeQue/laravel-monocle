<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\HttpClientLens;
use ExeQue\Monocle\Lenses\Request\JsonCapture;
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

    it('records the request payload masked and the response through its capture', function (): void {
        Http::post('https://api.example.com/orders', ['name' => 'ada', 'password' => 'hunter22hunter22']);

        $row = app(Eyepiece::class)->finalize()->rows('http')[0];

        expect($row['request_payload'])->toBe(['name' => 'ada', 'password' => 'hunt****'])
            ->and($row['response_capture']['capture'])->toBe(JsonCapture::class)
            ->and($row['response_capture']['cards'][0]['items'])->toBe(['ok' => true])
            ->and($row['response_capture']['raw'])->toBe('{"ok":true}')
            ->and($row['response_body'])->toBeNull();
    });

    it('masks secrets in captured responses and their raw body', function (): void {
        Http::fake(['auth.example.com/*' => Http::response(['access_token' => 'abcdefghijklmnop', 'expires_in' => 3600], 200)]);

        Http::asForm()->post('https://auth.example.com/oauth/token', ['grant_type' => 'client_credentials']);

        $capture = app(Eyepiece::class)->finalize()->rows('http')[0]['response_capture'];

        expect($capture['cards'][0]['items'])->toBe(['access_token' => 'abcd****', 'expires_in' => 3600])
            ->and($capture['raw'])->toBe('{"access_token":"abcd****","expires_in":3600}');
    });

    it('records every request sent in a pool', function (): void {
        Http::pool(fn ($pool) => [
            $pool->as('orders')->get('https://api.example.com/orders'),
            $pool->as('health')->get('https://down.example.com/health'),
        ]);

        $rows = app(Eyepiece::class)->finalize()->rows('http');

        expect(array_column($rows, 'status'))->toEqualCanonicalizing([201, 503])
            ->and(array_column($rows, 'duration'))->each->not->toBeNull()
            ->and(array_column($rows, 'pool'))->toBe([1, 1]);
    });

    it('numbers each pool and batch and leaves single requests out of them', function (): void {
        Http::pool(fn ($pool) => [$pool->get('https://api.example.com/a'), $pool->get('https://api.example.com/b')]);
        Http::get('https://api.example.com/single');
        Http::batch(fn ($batch) => [$batch->get('https://api.example.com/c'), $batch->get('https://api.example.com/d')])->send();

        $rows = app(Eyepiece::class)->finalize()->rows('http');

        expect(array_column($rows, 'pool', 'url'))->toBe([
            'https://api.example.com/a' => 1,
            'https://api.example.com/b' => 1,
            'https://api.example.com/single' => null,
            'https://api.example.com/c' => 2,
            'https://api.example.com/d' => 2,
        ]);
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

        Http::post('https://down.example.com/orders', ['name' => 'ada']);

        $rows = app(Eyepiece::class)->finalize()->rows('http');
        $withBody = collect($rows)->first(fn (array $row): bool => $row['request_body'] !== null);

        // A response without a capture for its content type keeps its body as is.
        expect($withBody['request_body'])->toBe('{"name":"ada"}')
            ->and($withBody['response_body'])->toBe('nope');
    });

    it('summarizes nothing without calls', function (): void {
        expect(app(HttpClientLens::class)->summarize(app(Eyepiece::class)->finalize()))->toBe([]);
    });
});
