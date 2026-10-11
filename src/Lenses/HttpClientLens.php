<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Contracts\ResponseCapture;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Request\JsonCapture;
use ExeQue\Monocle\LensRegistry;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Batch;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * Outgoing HTTP client calls with masked headers and query, sizes, status
 * and duration.
 */
final class HttpClientLens implements Advises, Configurable, Lens, Presentable, Resettable, Summarizable
{
    private bool $bodies = false;

    /** @var array<string, class-string<ResponseCapture>> content type pattern => capture */
    private array $responseTypes = ['application/json' => JsonCapture::class, 'application/*+json' => JsonCapture::class];

    /** @var array<int, array{at: float, pool: int|null, call: string}> */
    private array $pending = [];

    /** @var WeakMap<object, int> The pool or batch each running Http::pool() / Http::batch() was given. */
    private WeakMap $pools;

    private int $poolCount = 0;

    public function __construct(
        private readonly Eyepiece $eyepiece,
        private readonly Application $app,
    ) {
        $this->pools = new WeakMap();
    }

    public function key(): string
    {
        return 'http';
    }

    public function description(): string
    {
        return 'Outgoing HTTP client calls (Http facade): method, url (query masked), request headers (masked) and size, status, response headers and size, duration, failed flag, request_payload (a JSON or form body as masked fields), response_capture (cards from the capture registered for the response content type under capture.response_types, JSON by default, with the raw body), the exception for connection failures, and timing (cURL transfer stats: dns, connect, tls, wait (time to first byte), download and redirect in ms, the remote address, protocol and redirects followed; absent for faked calls and failed connections), pool (a number shared by the requests sent together by one Http::pool() or Http::batch(), null otherwise). Bodies only with the option bodies=true.';
    }

    public function advice(): string
    {
        return 'Look here when an outgoing API call is slow, fails or sends the wrong data.';
    }

    public function configure(array $options): void
    {
        $this->bodies = (bool) ($options['bodies'] ?? $this->bodies);
        $this->responseTypes = (array) ($options['capture']['response_types'] ?? $this->responseTypes);
    }

    public function boot(): void
    {
        $this->eyepiece->listen(RequestSending::class, function (RequestSending $event): void {
            $this->pending[spl_object_id($event->request)] = ['at' => $this->eyepiece->now(), 'pool' => $this->pool(), 'call' => $this->call($event->request)];
        });

        $this->eyepiece->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $pending = $this->take($event->request);

            if (! $this->eyepiece->recording()) {
                return;
            }

            $status = $event->response->status();
            $body = $event->response->body();
            $capture = $this->captureResponse($event->request, $event->response, $body);

            $this->eyepiece->push('http', [
                ...$this->describe($event->request),
                'status' => $status,
                'response_headers' => $this->eyepiece->masker(self::class)->headers($event->response->headers()),
                'response_bytes' => strlen($body),
                'response_body' => $this->bodies && $capture === null ? $this->eyepiece->masker(self::class)->body($body, $event->response->header('Content-Type')) : null,
                'response_capture' => $capture,
                'duration' => $this->duration($pending['at'] ?? null, $event->response->handlerStats()),
                'timing' => $this->timing($event->response->handlerStats()),
                'failed' => $status >= 400,
                'exception' => null,
                'pool' => $pending['pool'] ?? null,
            ]);
        });

        $this->eyepiece->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            $pending = $this->take($event->request);
            $started = $pending['at'] ?? null;

            if (! $this->eyepiece->recording()) {
                return;
            }

            $this->eyepiece->push('http', [
                ...$this->describe($event->request),
                'status' => null,
                'response_headers' => [],
                'response_bytes' => null,
                'response_body' => null,
                'response_capture' => null,
                // Guzzle keeps no transfer stats on a failed connection; the wall clock is all there is.
                'duration' => $started === null ? null : round($this->eyepiece->now() - $started, 3),
                'timing' => null,
                'failed' => true,
                'exception' => ThrowableChain::describe($event->exception),
                // A connection that fails before the request is sent never fired RequestSending.
                'pool' => $pending === null ? $this->pool() : $pending['pool'],
            ]);
        });
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->pools = new WeakMap();
        $this->poolCount = 0;
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('http');

        if ($rows === []) {
            return Summary::make();
        }

        $failed = array_filter($rows, fn (array $row): bool => (bool) $row['failed']);
        $byHost = [];
        $ms = 0.0;

        foreach ($rows as $row) {
            $host = (string) (parse_url((string) $row['url'], PHP_URL_HOST) ?: '?');
            $byHost[$host] = ($byHost[$host] ?? 0) + 1;
            $ms += (float) ($row['duration'] ?? 0);
        }

        return Summary::make()
            ->total('http_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->totals([
                'http_by_host' => $byHost,
            ])
            ->total('http_calls', count($rows), 'calls')
            ->total('http_failed', count($failed), 'failed')
            ->errors(array_map(
                fn (array $row): string => sprintf('HTTP call failed: %s %s (%s)', $row['method'], $row['url'], $row['status'] ?? $row['exception']['message'] ?? 'no response'),
                array_slice(array_values($failed), 0, 3),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Request $request): array
    {
        $masker = $this->eyepiece->masker(self::class);

        return [
            'method' => $request->method(),
            'url' => $this->maskedUrl($request->url()),
            'host' => (string) parse_url($request->url(), PHP_URL_HOST),
            'endpoint' => $request->method().' '.$this->endpoint($request->url()),
            'request_headers' => $masker->headers($request->headers()),
            'request_bytes' => strlen($request->body()),
            'request_payload' => $this->payload($request),
            'request_body' => $this->bodies ? $masker->body($request->body(), $request->header('Content-Type')[0] ?? null) : null,
        ];
    }

    /**
     * A JSON or form body as masked fields, like the input of an incoming request.
     *
     * @return array<array-key, mixed>|null
     */
    private function payload(Request $request): ?array
    {
        try {
            $data = $request->data();
        } catch (Throwable) {
            return null;
        }

        return is_array($data) && $data !== [] ? $this->eyepiece->masker(self::class)->inputs($data) : null;
    }

    /**
     * The response as cards from the capture registered for its content type,
     * using the same captures as incoming responses.
     *
     * @return array{capture: string, cards: list<array<string, mixed>>, raw: string}|null
     */
    private function captureResponse(Request $request, ClientResponse $response, string $body): ?array
    {
        $type = strtolower(trim(Str::before((string) $response->header('Content-Type'), ';')));
        $class = null;

        foreach ($this->responseTypes as $pattern => $candidate) {
            if ($type !== '' && Str::is(strtolower((string) $pattern), $type) && LensRegistry::available($candidate)) {
                $class = $candidate;

                break;
            }
        }

        if ($class === null) {
            return null;
        }

        try {
            $incoming = HttpRequest::create($request->url(), $request->method(), [], [], [], [], $request->body());
            $incoming->headers->replace($request->headers());
            $capture = $this->app->make($class);
            $cards = $capture instanceof ResponseCapture
                ? $capture->capture($incoming, new HttpResponse($body, $response->status(), $response->headers()))
                : null;
        } catch (Throwable $e) {
            $this->eyepiece->failed($e);

            return null;
        }

        $masker = $this->eyepiece->masker(self::class);

        return $cards === null ? null : ['capture' => $class, 'cards' => $masker->cards($cards), 'raw' => $masker->body($body, $type)];
    }

    /**
     * The url without its query, ids in the path collapsed to {id}, so calls
     * to the same endpoint group together.
     */
    private function endpoint(string $url): string
    {
        $path = (string) strtok($url, '?');

        return (string) preg_replace('#/(\d+|[0-9a-f-]{32,36}|[0-9a-f]{24,})(?=/|$)#i', '/{id}', $path);
    }

    private function maskedUrl(string $url): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ($query === '') {
            return $url;
        }

        parse_str($query, $parameters);
        $masked = str_replace('%2A', '*', http_build_query($this->eyepiece->masker(self::class)->inputs($parameters)));

        return str_replace($query, $masked, $url);
    }

    /**
     * The number of the Http::pool() or Http::batch() sending this request, if any.
     */
    private function pool(): ?int
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            $owner = $frame['object'] ?? null;

            if ($owner instanceof PendingRequest && $frame['function'] === 'pool') {
                return $this->pools[$owner] ??= ++$this->poolCount;
            }

            if ($owner instanceof Batch && $frame['function'] === 'send') {
                return $this->pools[$owner] ??= ++$this->poolCount;
            }
        }

        return null;
    }

    /**
     * The pending start of a request. A failed connection is reported with a
     * new request object, so it is matched by method and url instead.
     *
     * @return array{at: float, pool: int|null, call: string}|null
     */
    private function take(Request $request): ?array
    {
        $id = spl_object_id($request);

        if (! isset($this->pending[$id])) {
            $call = $this->call($request);
            $id = array_find_key($this->pending, fn (array $pending): bool => $pending['call'] === $call);
        }

        if ($id === null) {
            return null;
        }

        $pending = $this->pending[$id];
        unset($this->pending[$id]);

        return $pending;
    }

    private function call(Request $request): string
    {
        return $request->method().' '.$request->url();
    }

    /**
     * Where the time of a call went, from the transfer stats of the handler
     * (cURL): each phase in ms, the address connected to, the protocol and
     * the number of redirects followed. Null for faked calls.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>|null
     */
    private function timing(array $stats): ?array
    {
        if (! isset($stats['total_time'])) {
            return null;
        }

        $at = fn (string $key): float => (float) ($stats[$key] ?? 0.0);
        $phase = fn (float $from, float $to): ?float => $to > 0.0 && $to >= $from ? round(($to - $from) * 1000, 3) : null;

        $timing = [
            'dns' => $phase(0.0, $at('namelookup_time')),
            'connect' => $phase($at('namelookup_time'), $at('connect_time')),
            'tls' => $phase($at('connect_time'), $at('appconnect_time')),
            'wait' => $phase(max($at('pretransfer_time'), $at('appconnect_time'), $at('connect_time')), $at('starttransfer_time')),
            'download' => $phase($at('starttransfer_time'), $at('total_time')),
            'redirect' => $at('redirect_time') > 0.0 ? round($at('redirect_time') * 1000, 3) : null,
            'remote' => isset($stats['primary_ip']) && $stats['primary_ip'] !== '' ? $stats['primary_ip'].(isset($stats['primary_port']) ? ':'.$stats['primary_port'] : '') : null,
            'protocol' => $this->protocol($stats['http_version'] ?? null),
            'redirects' => (int) ($stats['redirect_count'] ?? 0) ?: null,
        ];

        return array_filter($timing, fn (mixed $value): bool => $value !== null);
    }

    private function protocol(mixed $version): ?string
    {
        return match ((int) $version) {
            CURL_HTTP_VERSION_1_0 => 'HTTP/1.0',
            CURL_HTTP_VERSION_1_1 => 'HTTP/1.1',
            CURL_HTTP_VERSION_2_0 => 'HTTP/2',
            30 => 'HTTP/3',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function duration(?float $started, array $stats): ?float
    {
        try {
            if (isset($stats['total_time'])) {
                return round((float) $stats['total_time'] * 1000, 3);
            }
        } catch (Throwable) {
            // Fall through to the wall clock below.
        }

        return $started === null ? null : round($this->eyepiece->now() - $started, 3);
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.http')
            ->columns(['method', 'status', 'url', 'request_bytes', 'response_bytes', 'pool', 'duration'])
            ->detailsOnly(['exception', 'failed', 'timing', 'host', 'endpoint'])
            ->groupBy(['endpoint' => 'by endpoint', 'host' => 'by host', 'pool' => 'by pool', 'status' => 'by status'])
            ->details('monocle::details.http')
            ->format(['status' => 'http-status', 'pool' => ['prefix', '#']]);
    }
}
