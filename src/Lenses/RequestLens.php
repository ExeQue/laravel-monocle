<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Contracts\ResponseCapture;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture;
use ExeQue\Monocle\Lenses\Request\JsonCapture;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Folio\Events\ViewMatched;
use Laravel\Octane\Events\RequestReceived;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Owns the HTTP capture lifecycle: starts it, records request/response facts,
 * lays down the phase spans and persists at terminate.
 */
final class RequestLens implements Advises, Configurable, Finalizable, Lens, Resettable, Summarizable
{
    public const string LABEL_HEADER = 'X-Monocle-Label';


    private Application $app;

    private ?Span $phase = null;

    private ?float $routeMatchedAt = null;

    private ?float $responsePreparedAt = null;

    private int $requestsInProcess = 0;

    /** @var array<string, class-string<ResponseCapture>> Content type pattern => capture. */
    private array $responseTypes = ['application/json' => JsonCapture::class, 'application/*+json' => JsonCapture::class, 'text/html' => InertiaCapture::class];

    /** @var array<string, class-string<ResponseCapture>> Route name pattern => capture. */
    private array $routeCaptures = [];

    /** @var array<string, class-string<ResponseCapture>> Response header => capture. */
    private array $headerCaptures = ['X-Inertia' => InertiaCapture::class];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'request';
    }

    public function description(): string
    {
        return 'Request and response facts: method, url, route (name, action, parameters, middleware list), masked headers and input (the payload), files, cookies (decrypted, sensitive ones masked), auth user, status, response size, headers, and response_capture: cards from the capture registered for the route (capture.routes, e.g. one per Livewire component with calls, updates and data changes) or for the content type (capture.response_types, JSON by default), peak memory. Phase spans (bootstrap, middleware, routing, handle, terminate) live in the timeline.';
    }

    public function advice(): string
    {
        return 'Start here to confirm what was asked and answered: route and action, payload, headers, cookies, status and, for JSON and Livewire, the response itself.';
    }

    public function configure(array $options): void
    {
        $this->responseTypes = (array) ($options['capture']['response_types'] ?? $this->responseTypes);
        $this->routeCaptures = (array) ($options['capture']['routes'] ?? $this->routeCaptures);
        $this->headerCaptures = (array) ($options['capture']['headers'] ?? $this->headerCaptures);
    }

    public function boot(Application $app): void
    {
        $this->app = $app;
        if (! $app->runningInConsole()) {
            $this->startCapture(defined('LARAVEL_START') ? LARAVEL_START : null);
            $this->phase('bootstrap');
        }

        $app->booted(function (): void {
            if ($this->eyepiece->active() !== null) {
                $this->phase('middleware (request)');
            }
        });

        if (class_exists(RequestReceived::class)) {
            $this->eyepiece->listen(RequestReceived::class, function (object $event): void {
                if ($this->eyepiece->active() === null) {
                    $this->startCapture((float) ($event->request->server('REQUEST_TIME_FLOAT') ?: microtime(true)));
                    $this->phase('middleware (request)');
                }
            });
        }

        if (class_exists(ViewMatched::class)) {
            $this->eyepiece->listen(ViewMatched::class, $this->onFolioPage(...));
        }

        $this->eyepiece->listen(Routing::class, $this->onRouting(...));
        $this->eyepiece->listen(RouteMatched::class, $this->onRouteMatched(...));
        $this->eyepiece->listen(PreparingResponse::class, $this->onPreparingResponse(...));
        $this->eyepiece->listen(RequestHandled::class, $this->onRequestHandled(...));

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

    /**
     * A Folio page has no controller; the page file is the action.
     */
    private function onFolioPage(object $event): void
    {
        $capture = $this->eyepiece->active();

        if ($capture === null || ! $this->eyepiece->recording()) {
            return;
        }

        $view = $event->matchedView;
        $file = Str::after(str_replace('\\', '/', (string) $view->path), str_replace('\\', '/', base_path()).'/');

        $this->eyepiece->set('request', [
            'route' => [...($capture->section('request')['route'] ?? []), 'action' => $file],
            'folio' => array_filter([
                'page' => $file,
                'name' => method_exists($view, 'name') ? $view->name() : null,
                'mount_path' => $event->mountPath->path ?? $view->mountPath ?? null,
                'parameters' => $view->data ?? [],
            ], fn (mixed $value): bool => $value !== null && $value !== []),
        ]);
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
        $described = [...$this->describeResponse($response), 'cookies' => $this->cookies($event->request)];
        $described['response_capture'] = $this->captureResponse($event->request, $response);

        $this->eyepiece->set('request', $described);
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
        if ($this->onlyExceptedLivewire($request)) {
            return true;
        }

        $dashboard = trim((string) $this->eyepiece->config('dashboard.path', '_monocle'), '/');

        foreach ([$dashboard, $dashboard.'/*', ...(array) $this->eyepiece->config('except.paths', [])] as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Livewire update where every component matches except.livewire, such as
     * a dashboard polling itself (Pulse).
     */
    private function onlyExceptedLivewire(Request $request): bool
    {
        $patterns = (array) $this->eyepiece->config('except.livewire', []);
        $components = $request->isJson() || $request->has('components') ? $request->input('components') : null;

        if ($patterns === [] || ! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $name = $snapshot['memo']['name'] ?? null;

            if (! is_string($name) || ! Str::is($patterns, $name)) {
                return false;
            }
        }

        return true;
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
            'input' => $masker->inputs(Arr::except($request->input(), array_keys($request->query()))),
            'files' => $files,
            'headers' => $masker->headers($request->headers->all()),
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

        $type = (string) $response->headers->get('Content-Type');

        return [
            'status' => $response->getStatusCode(),
            'response_headers' => $this->eyepiece->masker()->headers($response->headers->all()),
            'response_bytes' => is_string($content) ? strlen($content) : null,
            'content_type' => $type === '' ? null : $type,
            'redirect' => $response->isRedirection() ? $response->headers->get('Location') : null,
        ];
    }

    /**
     * Cookie values once the request has been handled, so encrypted cookies
     * are already decrypted. The session cookie, the XSRF token and names that
     * match the input masking pattern are masked.
     *
     * @return array<string, mixed>
     */
    private function cookies(Request $request): array
    {
        $masker = $this->eyepiece->masker();
        $cookies = [];

        $raw = $this->rawCookies($request);

        foreach ($request->cookies->all() as $name => $value) {
            // Cookies the application could not decrypt (unencrypted ones, or another app's on the same host) keep their raw value.
            if ($value === null && isset($raw[$name])) {
                $value = Str::limit($raw[$name], 80);
            }

            $cookies[$name] = $value;
        }

        return $masker->cookies($cookies);
    }

    /**
     * The cookies as the browser sent them, before decryption.
     *
     * @return array<string, string>
     */
    private function rawCookies(Request $request): array
    {
        $cookies = [];

        foreach (explode(';', (string) $request->headers->get('Cookie')) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($name !== '') {
                $cookies[str_replace('.', '_', rawurldecode($name))] = rawurldecode($value);
            }
        }

        return $cookies;
    }

    /**
     * The response as presented by the capture registered for its route, a
     * response header or its content type, in that order. Null when none applies.
     *
     * @return array{capture: string, cards: list<array<string, mixed>>, raw: string|null}|null
     */
    private function captureResponse(Request $request, Response $response): ?array
    {
        $route = $request->route()?->getName();
        $type = strtolower(trim(Str::before((string) $response->headers->get('Content-Type'), ';')));

        $class = ($route === null ? null : $this->match($this->routeCaptures, $route))
            ?? $this->headerCapture($response)
            ?? ($type === '' ? null : $this->match($this->responseTypes, $type));

        if ($class === null) {
            return null;
        }

        try {
            $capture = $this->app->make($class);
            $cards = $capture instanceof ResponseCapture ? $capture->capture($request, $response) : null;
        } catch (Throwable $e) {
            $this->eyepiece->failed($e);

            return null;
        }

        if ($cards === null) {
            return null;
        }

        try {
            $raw = $response->getContent();
        } catch (Throwable) {
            $raw = null;
        }

        $masker = $this->eyepiece->masker();

        return ['capture' => $class, 'cards' => $masker->cards($cards), 'raw' => is_string($raw) ? $masker->body($raw, $type) : null];
    }

    private function headerCapture(Response $response): ?string
    {
        foreach ($this->headerCaptures as $header => $class) {
            if ($response->headers->has((string) $header)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param  array<string, class-string<ResponseCapture>>  $captures  pattern => capture
     */
    private function match(array $captures, string $subject): ?string
    {
        foreach ($captures as $pattern => $class) {
            if (Str::is(strtolower((string) $pattern), strtolower($subject))) {
                return $class;
            }
        }

        return null;
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
     * The controller runs between the last middleware handing the request on
     * and the first response preparation. Without middleware it starts at the
     * route match.
     */
    private function deriveControllerSpan(Capture $capture): void
    {
        if ($this->routeMatchedAt === null) {
            return;
        }

        $innermost = null;

        foreach ($capture->spansOfKind('middleware.request') as $span) {
            if (($span->meta['passed'] ?? false) !== true || $span->end === null) {
                if (($span->meta['group'] ?? null) === 'route') {
                    return;
                }

                continue;
            }

            if ($innermost === null || $span->end > $innermost->end) {
                $innermost = $span;
            }
        }

        $firstResponse = null;

        foreach ($capture->spansOfKind('middleware.response') as $span) {
            if ($firstResponse === null || $span->start < $firstResponse) {
                $firstResponse = $span->start;
            }
        }

        $start = $innermost !== null ? $innermost->end : $this->routeMatchedAt;
        $end = $this->responsePreparedAt ?? $firstResponse;

        if ($end === null || $end < $start) {
            return;
        }

        $controller = new Span(
            id: 'controller',
            name: 'controller',
            kind: 'controller',
            parentId: $innermost?->parentId ?? $this->handlePhaseId($capture),
            key: null,
            eyepiece: null,
        );

        $controller->start = $start;
        $controller->end = $end;
        $controller->endedBy = 'derived';
        $controller->meta = ['action' => $capture->section('request')['route']['action'] ?? null];

        $capture->spans[$controller->id] = $controller;

        // Spans opened inside the controller were started before it existed; move them under it.
        foreach ($capture->spans as $span) {
            if ($span === $controller) {
                continue;
            }

            if ($span->parentId !== $controller->parentId) {
                continue;
            }

            if (str_starts_with($span->kind, 'middleware')) {
                continue;
            }

            if ($span->start === null || $span->start < $start) {
                continue;
            }

            if (($span->end ?? $end) <= $end + 0.001) {
                $span->parentId = $controller->id;
            }
        }
    }

    private function handlePhaseId(Capture $capture): ?string
    {
        foreach ($capture->spansOfKind('phase') as $span) {
            if ($span->name === 'handle') {
                return $span->id;
            }
        }

        return null;
    }
}
