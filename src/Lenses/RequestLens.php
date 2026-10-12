<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Contracts\ResponseCapture;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Inertia\InertiaCapture;
use ExeQue\Monocle\Lenses\Packages\Livewire\LivewireUpdateCapture;
use ExeQue\Monocle\Lenses\Request\JsonCapture;
use ExeQue\Monocle\LensRegistry;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Support\TraceContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Folio\Events\ViewMatched;
use Laravel\Octane\Events\RequestReceived;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Owns the HTTP capture lifecycle: starts it, records request/response facts,
 * lays down the phase spans and persists at terminate.
 */
final class RequestLens implements Advises, Configurable, Finalizable, Lens, Presentable, Resettable, Summarizable
{
    public const string LABEL_HEADER = 'X-Monocle-Label';

    private string $exceptPattern = '';

    private ?string $exceptPatternFor = null;

    private Application $app;

    private ?Span $phase = null;

    private ?float $routeMatchedAt = null;

    private ?float $responsePreparedAt = null;

    private int $requestsInProcess = 0;

    /** The browser trace script, read once. */
    private ?string $traceScript = null;

    /** @var array<string, class-string<ResponseCapture>> Content type pattern => capture. */
    private array $responseTypes = [
        'application/json' => JsonCapture::class,
        'application/*+json' => JsonCapture::class,
        'text/html' => InertiaCapture::class,
    ];

    /** @var array<string, class-string<ResponseCapture>> Route name pattern => capture. */
    private array $routeCaptures = ['*livewire.update' => LivewireUpdateCapture::class];

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
        return 'Request and response facts: method, url, route (name, action, parameters, middleware list), masked headers and input (the payload), files, cookies (decrypted, sensitive ones masked), auth user, status, response size, headers, and response_capture: cards from the capture registered for the route (capture.routes, e.g. one per Livewire component with calls, updates and data changes) or for the content type (capture.response_types, JSON by default), peak memory. Phase spans (bootstrap, middleware, routing, handle, terminate) live in the timeline. With trace.browser on, HTML pages get a small script that sends X-Monocle-Parent on their same-origin fetch/XHR calls; a request carrying it joins the trace of that page capture as a child, or keeps missing_parent_id when this store does not hold it.';
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
            // Octane workers boot once, so each request they handle starts its capture here.
            $this->eyepiece->listen(RequestReceived::class, function (object $event): void {
                if ($this->eyepiece->active() !== null) {
                    return;
                }

                $this->startCapture($this->requestStartedAt($event->request));
                $this->phase('middleware (request)');
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

    public function summarize(Capture $capture): Summary
    {
        $request = $capture->section('request');

        return Summary::make()
            ->when(isset($request['response_bytes']))
            ->headline('response_bytes', $request['response_bytes'] ?? 0, 'response size', Unit::Bytes);
    }

    private function onRouting(Routing $event): void
    {
        $request = $event->request;

        $capture = $this->eyepiece->active();

        // Every request after the first in this process starts a fresh capture.
        if ($capture === null || $this->requestsInProcess > 0) {
            $capture = $this->startCapture($this->requestStartedAt($request));
            $this->phase('middleware (request)');
        }

        $this->requestsInProcess++;

        if ($this->isExcepted($request)) {
            $this->eyepiece->discard();

            return;
        }

        $this->joinTrace($capture, $request);
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

        if ($capture === null) {
            return;
        }

        if (! $this->eyepiece->recording()) {
            return;
        }

        $view = $event->matchedView;
        $file = $this->relativeToBase((string) $view->path);
        $route = $capture->section('request')['route'] ?? [];

        $folio = [
            'page' => $file,
            'name' => method_exists($view, 'name') ? $view->name() : null,
            'mount_path' => $event->mountPath->path ?? $view->mountPath ?? null,
            'parameters' => $view->data ?? [],
        ];

        $this->eyepiece->set('request', [
            'route' => [...$route, 'action' => $file],
            'folio' => array_filter($folio, fn (mixed $value): bool => $value !== null && $value !== []),
        ]);
    }

    /**
     * The first response prepared after the route matched is where the
     * controller ended; later ones come from middleware.
     */
    private function onPreparingResponse(PreparingResponse $event): void
    {
        if ($this->routeMatchedAt !== null && $this->responsePreparedAt === null) {
            $this->responsePreparedAt = $this->eyepiece->now();
        }
    }

    private function onRequestHandled(RequestHandled $event): void
    {
        $capture = $this->eyepiece->active();

        if ($capture === null) {
            return;
        }

        if (! $this->eyepiece->recording()) {
            return;
        }

        $request = $event->request;
        $response = $event->response;

        $this->eyepiece->describe(status: (string) $response->getStatusCode());

        $this->eyepiece->set('request', [
            ...$this->describeResponse($response),
            'cookies' => $this->cookies($request),
            'response_capture' => $this->captureResponse($request, $response),
        ]);
        $this->phase('terminate');

        $this->markNavigation($capture, $response);

        try {
            $this->injectTraceScript($capture, $response);
            $response->headers->set('X-Monocle-Id', $capture->id);
            $response->headers->set('Server-Timing', $this->serverTiming($capture));
        } catch (Throwable) {
            // Streamed or already-sent responses may refuse header changes.
        }
    }

    private function startCapture(?float $startedAt): Capture
    {
        $this->reset();

        return $this->eyepiece->start(CaptureType::Request, $startedAt);
    }

    /**
     * A request sent by a page with the browser trace script (trace.browser)
     * joins the trace of that page's capture as its child. A parent this
     * store does not hold is noted instead, and the capture stays a root.
     */
    private function joinTrace(Capture $capture, Request $request): void
    {
        if (! $this->eyepiece->config('trace.browser', false)) {
            return;
        }

        $parentId = $request->headers->get(TraceContext::PARENT_HEADER);

        if (! TraceContext::isCaptureId($parentId)) {
            return;
        }

        $parentId = strtolower($parentId);
        $parent = $this->app->make(CaptureReader::class)->indexRow($parentId);

        if ($parent === null) {
            $this->eyepiece->set('request', ['missing_parent_id' => $parentId]);

            return;
        }

        $capture->parentId = $parentId;
        $capture->traceId = (string) ($parent['trace_id'] ?? $parentId);
        $capture->linkedVia = 'browser';
    }

    /**
     * A call from a page that answered with a whole page (an HTML document,
     * or an Inertia page) was an SPA router fetching the next page.
     */
    private function markNavigation(Capture $capture, Response $response): void
    {
        if ($capture->linkedVia !== 'browser') {
            return;
        }

        if ($response->headers->has('X-Inertia')) {
            $capture->linkedVia = 'navigation';

            return;
        }

        if (! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return;
        }

        if (stripos((string) $response->getContent(), '</body>') === false) {
            return;
        }

        $capture->linkedVia = 'navigation';
    }

    /**
     * Put the browser trace script into an HTML page (trace.browser), so the
     * calls the page makes send its capture id along.
     */
    private function injectTraceScript(Capture $capture, Response $response): void
    {
        if (! $this->eyepiece->config('trace.browser', false)) {
            return;
        }

        if (! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return;
        }

        $html = $response->getContent();

        if (! is_string($html)) {
            return;
        }

        $nonce = $this->app->make(Vite::class)->cspNonce();
        // The dashboard's address, so the console can link each call to its capture; none when the dashboard is off.
        $dashboard = $this->app->make('router')->has('monocle.dashboard') ? $this->app->make('url')->route('monocle.dashboard') : null;
        $script = sprintf(
            '<script data-monocle-id="%s"%s%s>%s</script>',
            $capture->id,
            $dashboard === null ? '' : ' data-monocle-dashboard="'.e($dashboard).'"',
            $nonce === null ? '' : ' nonce="'.e($nonce).'"',
            $this->traceScript ??= (string) file_get_contents(dirname(__DIR__, 2).'/resources/trace/browser.js'),
        );

        $position = strripos($html, '</body>');
        $html = $position === false ? $html.$script : substr_replace($html, $script, $position, 0);

        $response->setContent($html);
        $response->headers->remove('Content-Length');
    }

    private function requestStartedAt(Request $request): float
    {
        return (float) ($request->server('REQUEST_TIME_FLOAT') ?: microtime(true));
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

        if ($this->startsTheCapture($name)) {
            $span->start = 0.0;
        }

        $span->meta(['memory_bytes' => memory_get_usage(true)]);

        $this->phase = $span;
    }

    /**
     * The first phase begins at the capture's start, not when Monocle got to
     * open it: bootstrap always, the request middleware when the capture was
     * started without a bootstrap phase (Octane, follow-up requests).
     */
    private function startsTheCapture(string $name): bool
    {
        if ($name === 'bootstrap') {
            return true;
        }

        return $name === 'middleware (request)' && $this->firstPhase();
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
        $patterns = [$dashboard, $dashboard.'/*', ...(array) $this->eyepiece->config('except.paths', [])];

        return preg_match($this->exceptPattern($patterns), $request->decodedPath()) === 1;
    }

    /**
     * The except paths as one regex, built once: matching them one by one
     * with Request::is() compiles a regex per pattern on every request.
     * Same rules as Str::is(): `*` matches anything, the rest literally.
     *
     * @param  list<string>  $patterns
     */
    private function exceptPattern(array $patterns): string
    {
        $key = implode("\n", $patterns);

        if ($this->exceptPatternFor === $key) {
            return $this->exceptPattern;
        }

        $alternatives = [];

        foreach ($patterns as $pattern) {
            // preg_quote() turns `*` into `\*`; turn that back into "anything".
            $alternatives[] = str_replace('\\*', '.*', preg_quote($pattern, '#'));
        }

        $this->exceptPattern = '#^(?:'.implode('|', $alternatives).')\z#u';
        $this->exceptPatternFor = $key;

        return $this->exceptPattern;
    }

    /**
     * A Livewire update where every component matches except.livewire, such as
     * a dashboard polling itself (Pulse).
     */
    private function onlyExceptedLivewire(Request $request): bool
    {
        $patterns = (array) $this->eyepiece->config('except.livewire', []);

        if ($patterns === []) {
            return false;
        }

        $isUpdate = $request->isJson() || $request->has('components');
        $components = $isUpdate ? $request->input('components') : null;

        if (! is_array($components)) {
            return false;
        }

        if ($components === []) {
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
        $masker = $this->eyepiece->masker(self::class);

        $path = '/'.ltrim($request->path(), '/');

        $this->eyepiece->describe(
            name: $request->getMethod().' '.$path,
            label: $request->headers->get(self::LABEL_HEADER),
        );

        $query = $request->query();
        // input() merges the query string in; it is recorded on its own under 'query'.
        $body = Arr::except($request->input(), array_keys($query));

        $this->eyepiece->set('request', [
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'path' => $path,
            'query' => $masker->inputs($query),
            'input' => $masker->inputs($body),
            'files' => $this->uploadedFiles($request),
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
     * @return list<array{key: string, name: string, size: int|false, mime: string}>
     */
    private function uploadedFiles(Request $request): array
    {
        $files = [];

        foreach ($request->allFiles() as $key => $file) {
            // A field like "photos[]" holds a list of uploads.
            $uploads = is_array($file) ? $file : [$file];

            foreach ($uploads as $uploaded) {
                $files[] = [
                    'key' => $key,
                    'name' => $uploaded->getClientOriginalName(),
                    'size' => $uploaded->getSize(),
                    'mime' => $uploaded->getClientMimeType(),
                ];
            }
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRoute(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameters() as $name => $value) {
            $parameters[$name] = $this->describeParameter($value);
        }

        return [
            'route' => [
                'name' => $route->getName(),
                'action' => $this->actionName($route),
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'parameters' => $parameters,
                'middleware' => $this->eyepiece->outside(fn (): array => $route->gatherMiddleware()),
                'domain' => $route->getDomain(),
            ],
        ];
    }

    /**
     * A bound model as Class#key, any other object as its class.
     */
    private function describeParameter(mixed $value): mixed
    {
        if (! is_object($value)) {
            return $value;
        }

        if (! method_exists($value, 'getKey')) {
            return $value::class;
        }

        return $value::class.'#'.$value->getKey();
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
            'response_headers' => $this->eyepiece->masker(self::class)->headers($response->headers->all()),
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
        $masker = $this->eyepiece->masker(self::class);
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
        // "application/json; charset=utf-8" is matched as "application/json".
        $type = strtolower(trim(Str::before((string) $response->headers->get('Content-Type'), ';')));
        $class = $this->captureClass($route, $response, $type);

        if ($class === null) {
            return null;
        }

        try {
            $cards = $this->eyepiece->outside(function () use ($class, $request, $response): ?array {
                $capture = $this->app->make($class);

                return $capture instanceof ResponseCapture ? $capture->capture($request, $response) : null;
            });
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

        $masker = $this->eyepiece->masker(self::class);

        return [
            'capture' => $class,
            'cards' => $masker->cards($cards),
            'raw' => is_string($raw) ? $masker->body($raw, $type) : null,
        ];
    }

    /**
     * @return class-string<ResponseCapture>|null
     */
    private function captureClass(?string $route, Response $response, string $type): ?string
    {
        if ($route !== null) {
            $class = $this->match($this->routeCaptures, $route);

            if ($class !== null) {
                return $class;
            }
        }

        $class = $this->headerCapture($response);

        if ($class !== null) {
            return $class;
        }

        if ($type === '') {
            return null;
        }

        return $this->match($this->responseTypes, $type);
    }

    private function headerCapture(Response $response): ?string
    {
        foreach ($this->headerCaptures as $header => $class) {
            if (! $response->headers->has((string) $header)) {
                continue;
            }

            if (LensRegistry::available($class)) {
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
            if (! Str::is(strtolower((string) $pattern), strtolower($subject))) {
                continue;
            }

            // A capture for a package that is not installed is skipped, like a lens for one.
            if (LensRegistry::available($class)) {
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
            $user = $this->eyepiece->outside(fn () => $request->user());
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

        $db = 0.0;

        foreach ($capture->rows('queries') as $row) {
            $db += (float) ($row['duration'] ?? 0);
        }

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

        // A route middleware that did not hand the request on means the controller never ran.
        if ($this->routeMiddlewareShortCircuited($capture)) {
            return;
        }

        $innermost = $this->innermostPassedMiddleware($capture);
        $start = $innermost !== null ? $innermost->end : $this->routeMatchedAt;
        $end = $this->responsePreparedAt ?? $this->firstResponseMiddlewareStart($capture);

        if ($end === null) {
            return;
        }

        if ($end < $start) {
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

        $this->adoptControllerSpans($capture, $controller);
    }

    private function routeMiddlewareShortCircuited(Capture $capture): bool
    {
        foreach ($capture->spansOfKind('middleware.request') as $span) {
            if ($this->handedOn($span)) {
                continue;
            }

            if (($span->meta['group'] ?? null) === 'route') {
                return true;
            }
        }

        return false;
    }

    /**
     * The middleware that handed the request on last: the controller starts
     * where its "before" span ended.
     */
    private function innermostPassedMiddleware(Capture $capture): ?Span
    {
        $innermost = null;

        foreach ($capture->spansOfKind('middleware.request') as $span) {
            if (! $this->handedOn($span)) {
                continue;
            }

            if ($innermost === null || $span->end > $innermost->end) {
                $innermost = $span;
            }
        }

        return $innermost;
    }

    private function handedOn(Span $span): bool
    {
        if (($span->meta['passed'] ?? false) !== true) {
            return false;
        }

        return $span->end !== null;
    }

    private function firstResponseMiddlewareStart(Capture $capture): ?float
    {
        $first = null;

        foreach ($capture->spansOfKind('middleware.response') as $span) {
            if ($first === null || $span->start < $first) {
                $first = $span->start;
            }
        }

        return $first;
    }

    /**
     * Spans opened inside the controller were started before it existed; move
     * them under it. Middleware spans stay where they are.
     */
    private function adoptControllerSpans(Capture $capture, Span $controller): void
    {
        $start = $controller->start;
        $end = $controller->end;

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

            // A small tolerance for spans that end in the same instant as the controller.
            if (($span->end ?? $end) <= $end + 0.001) {
                $span->parentId = $controller->id;
            }
        }
    }

    /**
     * Controller@method, or for a closure route where the closure is.
     */
    private function actionName(Route $route): string
    {
        $action = $route->getAction('uses');

        if (! $action instanceof Closure) {
            return $route->getActionName();
        }

        $function = new ReflectionFunction($action);
        $file = $this->relativeToBase((string) $function->getFileName());

        return 'Closure ('.$file.':'.$function->getStartLine().')';
    }

    /**
     * A path relative to the application root, with forward slashes on every OS.
     */
    private function relativeToBase(string $path): string
    {
        $base = str_replace('\\', '/', $this->app->basePath()).'/';

        return Str::after(str_replace('\\', '/', $path), $base);
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

    public function presentation(): Presentation
    {
        return Presentation::table()->tab('monocle::tabs.request')->includes(['auth']);
    }
}
