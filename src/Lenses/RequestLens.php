<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Owns the HTTP capture lifecycle: starts it, records request/response facts,
 * lays down the phase spans and persists at terminate.
 */
final class RequestLens implements Finalizable, Lens, Resettable, Summarizable
{
    public const string LABEL_HEADER = 'X-Monocle-Label';

    private Eyepiece $eyepiece;

    private Application $app;

    private ?Span $phase = null;

    private ?float $routeMatchedAt = null;

    private ?float $responsePreparedAt = null;

    private int $requestsInProcess = 0;

    public function key(): string
    {
        return 'request';
    }

    public function description(): string
    {
        return 'Request and response facts: method, url, route (name, action, parameters, middleware list), masked headers and input, files, auth user, status, response size and headers, peak memory. Phase spans (bootstrap, middleware, routing, handle, terminate) live in the timeline.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->app = $app;
        $this->eyepiece = $eyepiece;

        if (! $app->runningInConsole()) {
            $this->startCapture(defined('LARAVEL_START') ? LARAVEL_START : null);
            $this->phase('bootstrap');
        }

        $app->booted(function (): void {
            if ($this->eyepiece->active() !== null) {
                $this->phase('middleware (request)');
            }
        });

        $eyepiece->listen(Routing::class, $this->onRouting(...));
        $eyepiece->listen(RouteMatched::class, $this->onRouteMatched(...));
        $eyepiece->listen(PreparingResponse::class, $this->onPreparingResponse(...));
        $eyepiece->listen(RequestHandled::class, $this->onRequestHandled(...));

        $app->terminating(function (): void {
            if ($this->eyepiece->active() === null) {
                return;
            }

            $this->phase(null);
            $this->eyepiece->finalize();
        });
    }

    public function finalize(Capture $capture): void
    {
        $this->deriveControllerSpan($capture);

        $capture->sections['request'] = array_merge($capture->section('request'), [
            'memory_peak_bytes' => memory_get_peak_usage(true),
        ]);
    }

    public function reset(): void
    {
        $this->phase = null;
        $this->routeMatchedAt = null;
        $this->responsePreparedAt = null;
    }

    public function summarize(Capture $capture): array
    {
        $request = $capture->section('request');

        return [
            'totals' => [
                'memory_peak_mb' => isset($request['memory_peak_bytes']) ? round($request['memory_peak_bytes'] / 1048576, 1) : null,
                'response_bytes' => $request['response_bytes'] ?? null,
            ],
        ];
    }

    private function onRouting(Routing $event): void
    {
        $request = $event->request;

        if ($this->eyepiece->active() === null || $this->requestsInProcess > 0) {
            $this->startCapture((float) ($request->server('REQUEST_TIME_FLOAT') ?: microtime(true)));
            $this->phase('middleware (request)');
        }

        $this->requestsInProcess++;

        if ($this->isExcepted($request)) {
            $this->eyepiece->discard();

            return;
        }

        $this->recordRequest($request);
        $this->phase('routing');
    }

    private function onRouteMatched(RouteMatched $event): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->routeMatchedAt = $this->eyepiece->now();
        $this->eyepiece->set('request', $this->describeRoute($event->route));
        $this->phase('handle');
    }

    private function onPreparingResponse(PreparingResponse $event): void
    {
        if ($this->routeMatchedAt !== null && $this->responsePreparedAt === null) {
            $this->responsePreparedAt = $this->eyepiece->now();
        }
    }

    private function onRequestHandled(RequestHandled $event): void
    {
        $capture = $this->eyepiece->active();

        if ($capture === null || ! $this->eyepiece->recording()) {
            return;
        }

        $response = $event->response;

        $this->eyepiece->describe(status: (string) $response->getStatusCode());
        $this->eyepiece->set('request', $this->describeResponse($response));
        $this->phase('terminate');

        try {
            $response->headers->set('X-Monocle-Id', $capture->id);
            $response->headers->set('Server-Timing', $this->serverTiming($capture));
        } catch (Throwable) {
            // Streamed or already-sent responses may refuse header changes.
        }
    }

    private function startCapture(?float $startedAt): void
    {
        $this->reset();
        $this->eyepiece->start(CaptureType::Request, $startedAt);
    }

    /**
     * Close the current phase span and open the next one, or just close when
     * $name is null. Phases are siblings at the root of the timeline.
     */
    private function phase(?string $name): void
    {
        if ($this->eyepiece->active() === null) {
            return;
        }

        $this->phase?->stop();
        $this->phase = null;

        if ($name === null) {
            return;
        }

        $span = $this->eyepiece->span($name, 'phase');
        $span->parent(null);
        $this->eyepiece->startSpan($span, origin: false);
        $span->parentId = null;

        if ($name === 'bootstrap' || $name === 'middleware (request)' && $this->firstPhase()) {
            $span->start = 0.0;
        }

        $span->meta(['memory_bytes' => memory_get_usage(true)]);

        $this->phase = $span;
    }

    private function firstPhase(): bool
    {
        $capture = $this->eyepiece->active();

        return $capture !== null && count($capture->spansOfKind('phase')) === 1;
    }

    private function isExcepted(Request $request): bool
    {
        foreach ((array) $this->eyepiece->config('except.paths', []) as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function recordRequest(Request $request): void
    {
        $masker = $this->eyepiece->masker();

        $this->eyepiece->describe(
            name: $request->getMethod().' /'.ltrim($request->path(), '/'),
            label: $request->headers->get(self::LABEL_HEADER),
        );

        $files = [];

        foreach ($request->allFiles() as $key => $file) {
            foreach (is_array($file) ? $file : [$file] as $uploaded) {
                $files[] = [
                    'key' => $key,
                    'name' => $uploaded->getClientOriginalName(),
                    'size' => $uploaded->getSize(),
                    'mime' => $uploaded->getClientMimeType(),
                ];
            }
        }

        $this->eyepiece->set('request', [
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'path' => '/'.ltrim($request->path(), '/'),
            'query' => $masker->inputs($request->query()),
            'input' => $masker->inputs($request->except(array_keys($request->query()))),
            'files' => $files,
            'headers' => $masker->headers($request->headers->all()),
            'cookies' => array_keys($request->cookies->all()),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'ajax' => $request->ajax(),
            'livewire' => $request->headers->has('X-Livewire'),
            'precognitive' => $request->headers->has('Precognition'),
            'locale' => $this->app->getLocale(),
            'auth' => $this->describeAuth($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRoute(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameters() as $name => $value) {
            $parameters[$name] = is_object($value) ? $value::class.(method_exists($value, 'getKey') ? '#'.$value->getKey() : '') : $value;
        }

        return [
            'route' => [
                'name' => $route->getName(),
                'action' => $route->getActionName(),
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'parameters' => $parameters,
                'middleware' => $route->gatherMiddleware(),
                'domain' => $route->getDomain(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeResponse(Response $response): array
    {
        $content = null;

        try {
            $content = $response->getContent();
        } catch (Throwable) {
            // Streamed responses have no buffered content.
        }

        return [
            'status' => $response->getStatusCode(),
            'response_headers' => $this->eyepiece->masker()->headers($response->headers->all()),
            'response_bytes' => is_string($content) ? strlen($content) : null,
            'content_type' => $response->headers->get('Content-Type'),
            'redirect' => $response->isRedirection() ? $response->headers->get('Location') : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeAuth(Request $request): ?array
    {
        try {
            $user = $request->user();
        } catch (Throwable) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        return [
            'class' => $user::class,
            'id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
        ];
    }

    private function serverTiming(Capture $capture): string
    {
        $parts = ['app;dur='.round($capture->duration(), 1)];

        foreach ($capture->spansOfKind('phase') as $span) {
            if ($span->duration() !== null) {
                $parts[] = Str::slug($span->name).';dur='.round($span->duration(), 1);
            }
        }

        $db = array_sum(array_map(fn (array $row): float => (float) ($row['duration'] ?? 0), $capture->rows('queries')));

        if ($db > 0) {
            $parts[] = 'db;dur='.round($db, 1);
        }

        return implode(', ', $parts);
    }

    /**
     * The controller runs between the innermost middleware calling $next and
     * the first response preparation. Without middleware it starts at the
     * route match.
     */
    private function deriveControllerSpan(Capture $capture): void
    {
        if ($this->routeMatchedAt === null) {
            return;
        }

        $innermost = null;

        foreach ($capture->spansOfKind('middleware') as $span) {
            if (($span->meta['passed'] ?? false) !== true || ! isset($span->meta['next_called_at'])) {
                if (($span->meta['group'] ?? null) === 'route') {
                    return;
                }

                continue;
            }

            if ($innermost === null || $span->meta['next_called_at'] > $innermost->meta['next_called_at']) {
                $innermost = $span;
            }
        }

        $start = $innermost?->meta['next_called_at'] ?? $this->routeMatchedAt;
        $end = $this->responsePreparedAt ?? $innermost?->meta['next_returned_at'] ?? null;

        if ($end === null || $end < $start) {
            return;
        }

        $controller = new Span(
            id: 'controller',
            name: 'controller',
            kind: 'controller',
            parentId: $innermost?->id,
            key: null,
            eyepiece: null,
        );

        $controller->start = $start;
        $controller->end = $end;
        $controller->endedBy = 'derived';
        $controller->meta = ['action' => $capture->section('request')['route']['action'] ?? null];

        $capture->spans[$controller->id] = $controller;
    }
}
