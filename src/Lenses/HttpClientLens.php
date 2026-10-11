<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Throwable;

/**
 * Outgoing HTTP client calls with masked headers and query, sizes, status
 * and duration.
 */
final class HttpClientLens implements Advises, Configurable, Lens, Resettable, Summarizable
{
    private Eyepiece $eyepiece;

    private bool $bodies = false;

    /** @var array<int, float> */
    private array $pending = [];

    public function key(): string
    {
        return 'http';
    }

    public function description(): string
    {
        return 'Outgoing HTTP client calls (Http facade): method, url (query masked), request headers (masked) and size, status, response headers and size, duration, failed flag and the exception for connection failures. Bodies only with the option bodies=true.';
    }

    public function advice(): string
    {
        return 'Look here when an outgoing API call is slow, fails or sends the wrong data.';
    }

    public function configure(array $options): void
    {
        $this->bodies = (bool) ($options['bodies'] ?? $this->bodies);
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $eyepiece->listen(RequestSending::class, function (RequestSending $event): void {
            $this->pending[spl_object_id($event->request)] = $this->eyepiece->now();
        });

        $eyepiece->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $started = $this->take($event->request);

            if (! $this->eyepiece->recording()) {
                return;
            }

            $status = $event->response->status();
            $body = $event->response->body();

            $this->eyepiece->push('http', [
                ...$this->describe($event->request),
                'status' => $status,
                'response_headers' => $this->eyepiece->masker()->headers($event->response->headers()),
                'response_bytes' => strlen($body),
                'response_body' => $this->bodies ? $body : null,
                'duration' => $this->duration($started, $event->response->handlerStats()),
                'failed' => $status >= 400,
                'exception' => null,
            ]);
        });

        $eyepiece->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            $started = $this->take($event->request);

            if (! $this->eyepiece->recording()) {
                return;
            }

            $this->eyepiece->push('http', [
                ...$this->describe($event->request),
                'status' => null,
                'response_headers' => [],
                'response_bytes' => null,
                'response_body' => null,
                'duration' => $started === null ? null : round($this->eyepiece->now() - $started, 3),
                'failed' => true,
                'exception' => ['class' => $event->exception::class, 'message' => $event->exception->getMessage()],
            ]);
        });
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('http');

        if ($rows === []) {
            return [];
        }

        $failed = array_filter($rows, fn (array $row): bool => (bool) $row['failed']);
        $byHost = [];
        $ms = 0.0;

        foreach ($rows as $row) {
            $host = (string) (parse_url((string) $row['url'], PHP_URL_HOST) ?: '?');
            $byHost[$host] = ($byHost[$host] ?? 0) + 1;
            $ms += (float) ($row['duration'] ?? 0);
        }

        return [
            'totals' => [
                'http_calls' => count($rows),
                'http_ms' => round($ms, 3),
                'http_failed' => count($failed),
                'http_by_host' => $byHost,
            ],
            'warnings' => array_map(
                fn (array $row): string => sprintf('HTTP call failed: %s %s (%s)', $row['method'], $row['url'], $row['status'] ?? $row['exception']['message'] ?? 'no response'),
                array_slice(array_values($failed), 0, 3),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Request $request): array
    {
        $masker = $this->eyepiece->masker();

        return [
            'method' => $request->method(),
            'url' => $this->maskedUrl($request->url()),
            'request_headers' => $masker->headers($request->headers()),
            'request_bytes' => strlen($request->body()),
            'request_body' => $this->bodies ? $request->body() : null,
        ];
    }

    private function maskedUrl(string $url): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ($query === '') {
            return $url;
        }

        parse_str($query, $parameters);
        $masked = str_replace('%2A', '*', http_build_query($this->eyepiece->masker()->inputs($parameters)));

        return str_replace($query, $masked, $url);
    }

    private function take(Request $request): ?float
    {
        $id = spl_object_id($request);
        $started = $this->pending[$id] ?? null;
        unset($this->pending[$id]);

        return $started;
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
}
