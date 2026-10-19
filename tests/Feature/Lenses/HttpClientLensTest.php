<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\HttpClientLens;
use ExeQue\Monocle\Lenses\Request\JsonCapture;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use stdClass;

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

    it('records calls whose connection fails, alone and in a pool', function (): void {
        Http::fake(['unreachable.example.com/*' => Http::failedConnection('cURL error 6: Could not resolve host')]);

        try {
            Http::get('https://unreachable.example.com/alone');
        } catch (ConnectionException) {
        }

        Http::pool(fn ($pool) => [
            $pool->get('https://unreachable.example.com/pooled'),
            $pool->get('https://api.example.com/fine'),
        ]);

        $rows = collect(app(Eyepiece::class)->finalize()->rows('http'))->keyBy('url');

        expect($rows)->toHaveCount(3)
            ->and($rows['https://unreachable.example.com/alone']['status'])->toBeNull()
            ->and($rows['https://unreachable.example.com/alone']['failed'])->toBeTrue()
            ->and($rows['https://unreachable.example.com/alone']['exception']['message'])->toContain('Could not resolve host')
            ->and($rows['https://unreachable.example.com/pooled']['pool'])->toBe(1)
            ->and($rows['https://unreachable.example.com/pooled']['failed'])->toBeTrue()
            ->and($rows['https://api.example.com/fine']['status'])->toBe(201);
    });

    it('times a real call whose connection is refused', function (): void {
        try {
            Http::connectTimeout(1)->get('http://127.0.0.1:1/');
        } catch (ConnectionException) {
        }

        $row = app(Eyepiece::class)->finalize()->rows('http')[0];

        expect($row['status'])->toBeNull()
            ->and($row['failed'])->toBeTrue()
            ->and($row['exception']['message'])->toContain('127.0.0.1:1')
            ->and($row['duration'])->toBeFloat()
            ->and($row['timing'])->toBeNull()
            ->and($row['exception']['previous'][0]['class'])->toBe(\GuzzleHttp\Exception\ConnectException::class);
    });

    it('splits cURL transfer stats into phases', function (): void {
        $timing = new ReflectionMethod(HttpClientLens::class, 'timing')->invoke(app(HttpClientLens::class), [
            'namelookup_time' => 0.004, 'connect_time' => 0.014, 'appconnect_time' => 0.044, 'pretransfer_time' => 0.0441,
            'starttransfer_time' => 0.244, 'total_time' => 0.25, 'redirect_time' => 0.0, 'redirect_count' => 0,
            'primary_ip' => '93.184.216.34', 'primary_port' => 443, 'http_version' => CURL_HTTP_VERSION_2_0,
        ]);

        expect($timing)->toBe([
            'dns' => 4.0, 'connect' => 10.0, 'tls' => 30.0, 'wait' => 199.9, 'download' => 6.0,
            'remote' => '93.184.216.34:443', 'protocol' => 'HTTP/2',
        ])
            ->and(new ReflectionMethod(HttpClientLens::class, 'timing')->invoke(app(HttpClientLens::class), []))->toBeNull();
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

        expect($summary->totals)->toMatchArray(['http_calls' => 2, 'http_failed' => 1, 'http_by_host' => ['api.example.com' => 1, 'down.example.com' => 1]])
            ->and($summary->errors)->toBe(['HTTP call failed: GET https://down.example.com/health (503)']);
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

    it('leaves a streamed response body for the caller to read', function (): void {
        Http::fake(['stream.example.com/*' => fn (): FulfilledPromise => new FulfilledPromise(
            new PsrResponse(200, ['Content-Type' => 'application/json'], new NoSeekStream(Utils::streamFor('{"ok":true}'))),
        )]);
        $lens = app(HttpClientLens::class);
        $lens->configure(['bodies' => true]);
        $lens->boot($this->app, app(Eyepiece::class));

        $response = Http::withOptions(['stream' => true])->post('https://stream.example.com/events');

        $row = app(Eyepiece::class)->finalize()->rows('http')[0];

        expect($response->toPsrResponse()->getBody()->getContents())->toBe('{"ok":true}')
            ->and($row['status'])->toBe(200)
            ->and($row['response_headers'])->toBe(['Content-Type' => 'application/json'])
            ->and($row['response_bytes'])->toBeNull()
            ->and($row['response_body'])->toBeNull()
            ->and($row['response_capture'])->toBeNull()
            ->and($row['failed'])->toBeFalse();
    });

    it('formats the payload of an outgoing call with the capture for its response', function (): void {
        Http::fake(['graph.example.com/*' => Http::response(['data' => ['login' => true]], 200)]);
        $lens = app(HttpClientLens::class);
        $lens->configure(['capture' => ['response_types' => ['application/json' => \ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLCapture::class]]]);
        $lens->boot($this->app, app(Eyepiece::class));

        Http::post('https://graph.example.com/graphql', ['query' => 'mutation { login(password: "hunter22hunter22") }']);
        Http::post('https://graph.example.com/graphql', ['query' => '{ a }', 'token' => 'abcdefghijkl']);

        [$login, $plain] = array_values(array_filter(app(Eyepiece::class)->finalize()->rows('http'), fn (array $row): bool => ($row['response_capture']['capture'] ?? null) === \ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLCapture::class));

        expect($login['request_payload'])->toBe(['query' => 'mutation { login(password: "hunt****") }'])
            ->and($login['request_formats'])->toBe(['query' => ['format' => 'graphql', 'text' => "mutation {\n  login(password: \"hunt****\")\n}"]])
            ->and($login['response_capture']['cards'][0]['title'])->toBe('mutation')
            ->and($plain['request_payload'])->toBe(['query' => '{ a }', 'token' => 'abc****']);
    });

    it('summarizes nothing without calls', function (): void {
        expect(app(HttpClientLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });
    it('records nothing while no capture is recording', function (): void {
        app(Eyepiece::class)->discard();

        Http::get('https://api.example.com/unrecorded');
        event(new ConnectionFailed(new Request(new PsrRequest('GET', 'https://gone.example.com/')), new ConnectionException('gone')));

        expect(app(Eyepiece::class)->active()->rows('http'))->toBe([]);
    });

    it('records no payload when the body is not a list of fields or cannot be read', function (): void {
        Http::withBody('5', 'application/json')->post('https://api.example.com/scalar');

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('method')->andReturn('POST');
        $request->shouldReceive('url')->andReturn('https://gone.example.com/broken');
        $request->shouldReceive('body')->andReturn('broken');
        $request->shouldReceive('headers')->andReturn([]);
        $request->shouldReceive('data')->andThrow(new RuntimeException('unreadable body'));
        event(new ConnectionFailed($request, new ConnectionException('gone')));

        $rows = app(Eyepiece::class)->finalize()->rows('http');

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['url'])->toBe('https://api.example.com/scalar')
            ->and($rows[0]['request_payload'])->toBeNull()
            ->and($rows[1]['url'])->toBe('https://gone.example.com/broken')
            ->and($rows[1]['request_payload'])->toBeNull();
    });

    it('picks the first capture whose content type pattern matches and none for other types', function (): void {
        Http::fake([
            'vendor.example.com/*' => Http::response('{"data":[]}', 200, ['Content-Type' => 'application/vnd.api+json']),
            'image.example.com/*' => Http::response('png', 200, ['Content-Type' => 'image/png']),
        ]);

        Http::get('https://vendor.example.com/items');
        Http::get('https://image.example.com/logo.png');

        $rows = app(Eyepiece::class)->finalize()->rows('http');

        expect($rows[0]['response_capture']['capture'])->toBe(JsonCapture::class)
            ->and($rows[1]['response_capture'])->toBeNull();
    });

    it('captures nothing when the registered capture is not a response capture or cannot be made', function (): void {
        config(['monocle.throw_failures' => false]);
        $lens = collect(app(\ExeQue\Monocle\LensRegistry::class)->booted())->first(fn ($lens): bool => $lens instanceof HttpClientLens);

        $lens->configure(['capture' => ['response_types' => ['application/json' => stdClass::class]]]);
        Http::get('https://api.example.com/not-a-capture');

        $lens->configure(['capture' => ['response_types' => ['application/json' => 'Tests\\Fixtures\\MissingHttpCapture']]]);
        Http::get('https://api.example.com/missing-capture');

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('http');

        expect(array_column($rows, 'response_capture'))->toBe([null, null])
            ->and(array_column($rows, 'status'))->toBe([201, 201])
            ->and(json_encode($capture->health))->toContain('MissingHttpCapture');
    });

    it('leaves out transfer phases that were never reached and reads every protocol and remote', function (): void {
        $timing = new ReflectionMethod(HttpClientLens::class, 'timing');
        $lens = app(HttpClientLens::class);

        expect($timing->invoke($lens, [
            'namelookup_time' => 0.0, 'connect_time' => 0.01, 'appconnect_time' => 0.0, 'starttransfer_time' => 0.02,
            'total_time' => 0.015, 'primary_ip' => '10.0.0.1', 'http_version' => 30,
        ]))->toBe(['connect' => 10.0, 'wait' => 10.0, 'remote' => '10.0.0.1', 'protocol' => 'HTTP/3'])
            ->and($timing->invoke($lens, ['total_time' => 0.01, 'primary_ip' => '', 'http_version' => CURL_HTTP_VERSION_1_0]))->toBe(['download' => 10.0, 'protocol' => 'HTTP/1.0'])
            ->and($timing->invoke($lens, ['total_time' => 0.01, 'http_version' => CURL_HTTP_VERSION_1_1, 'redirect_time' => 0.002, 'redirect_count' => 1]))
            ->toBe(['download' => 10.0, 'redirect' => 2.0, 'protocol' => 'HTTP/1.1', 'redirects' => 1]);
    });

    it('falls back to the wall clock when the transfer stats cannot be read', function (): void {
        $duration = new ReflectionMethod(HttpClientLens::class, 'duration');
        $lens = app(HttpClientLens::class);

        expect($duration->invoke($lens, null, ['total_time' => new stdClass()]))->toBeNull()
            ->and($duration->invoke($lens, app(Eyepiece::class)->now(), ['total_time' => new stdClass()]))->toBeFloat();
    });

    it('describes itself for the agent and the dashboard', function (): void {
        $lens = app(HttpClientLens::class);
        $presentation = $lens->presentation();

        expect($lens->description())->toContain('Http::pool()')
            ->and($lens->advice())->toStartWith('Look here when an outgoing API call')
            ->and($presentation->tabView)->toBe('monocle::tabs.http')
            ->and($presentation->detailsView)->toBe('monocle::details.http')
            ->and($presentation->formats)->toBe(['status' => ['http-status'], 'pool' => ['prefix', '#']]);
    });
});
