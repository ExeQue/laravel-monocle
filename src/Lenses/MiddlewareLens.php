<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Middleware\TimedMiddleware;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Times every class-based middleware by wrapping it in the container. The
 * pipeline resolves middleware through Container::make(), so extending the
 * binding is enough; RouteMatched fires before the route pipeline is built.
 *
 * ponytail: closure middleware and instances created outside the container
 * are not timed.
 */
final class MiddlewareLens implements Advises, Configurable, Finalizable, Lens, Presentable, Summarizable
{
    private Application $app;

    private ?Kernel $kernel = null;

    /** @var array<class-string, true> */
    private array $wrapped = [];

    /** @var array<class-string, true> */
    private array $global = [];

    private float $slowMs = 50.0;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'middleware';
    }

    public function description(): string
    {
        return 'One row per class-based middleware, in the order the request passed through them: middleware (class), parameters, group, request_ms (work before it called $next), response_ms (work once $next returned), duration (both together), time, passed (false when it short-circuited), short_circuited_with (the HTTP status it answered with, or the one the exception it threw turns into: 401, 403, 422, 429, 500...), threw (the class of that exception) and slow (its own time is over slow_ms). The same work is in the timeline as middleware.request and middleware.response spans.';
    }

    public function advice(): string
    {
        return 'Look here when time goes before or after the controller, or when a request is redirected or rejected before it reaches it.';
    }

    public function configure(array $options): void
    {
        $this->slowMs = (float) ($options['slow_ms'] ?? $this->slowMs);
    }

    public function boot(Application $app): void
    {
        $this->app = $app;
        $app->booted(function () use ($app): void {
            $this->kernel = $this->resolveKernel($app);

            foreach ($this->globalMiddleware() as $middleware) {
                $this->global[$middleware] = true;
                $this->wrap($middleware, 'global');
            }
        });

        $this->eyepiece->listen(RouteMatched::class, function (RouteMatched $event): void {
            $routeMiddleware = $this->eyepiece->outside(fn (): array => $event->route->gatherMiddleware());

            foreach ($routeMiddleware as $middleware) {
                foreach ($this->resolveNames($middleware) as $class) {
                    // Global middleware listed on the route again keeps its global group.
                    $group = isset($this->global[$class]) ? 'global' : 'route';

                    $this->wrap($class, $group);
                }
            }
        });
    }

    /**
     * One row per middleware the request passed through, in order, from its
     * request and response spans.
     */
    public function finalize(Capture $capture): void
    {
        $responses = [];

        foreach ($capture->spansOfKind('middleware.response') as $span) {
            $responses[$span->name][] = $span;
        }

        $rows = [];

        foreach ($capture->spansOfKind('middleware.request') as $request) {
            // Pair each request span with the next unclaimed response span of the same middleware.
            $responses[$request->name] ??= [];
            $response = array_shift($responses[$request->name]);

            $class = $request->meta['class'] ?? null;
            $before = round($request->duration() ?? 0.0, 3);
            $after = $response instanceof Span ? round($response->duration() ?? 0.0, 3) : null;
            $ownMs = $before + ($after ?? 0.0);

            $rows[] = [
                'n' => count($rows) + 1,
                'middleware' => $class ?? $request->name,
                'parameters' => $request->meta['parameters'] ?? [],
                'group' => $request->meta['group'] ?? null,
                'request_ms' => $before,
                'response_ms' => $after,
                'duration' => round($ownMs, 3),
                'time' => round($request->start ?? 0.0, 3),
                'passed' => $request->meta['passed'] ?? true,
                'short_circuited_with' => $request->meta['short_circuited_with'] ?? null,
                'threw' => $request->meta['threw'] ?? null,
                'slow' => $this->isSlow($ownMs, $class),
            ];
        }

        if ($rows !== []) {
            $capture->sections['middleware'] = $rows;
        }
    }

    public function summarize(Capture $capture): Summary
    {
        $requests = $capture->spansOfKind('middleware.request');
        $spans = [...$requests, ...$capture->spansOfKind('middleware.response')];
        $own = [];
        $classes = [];
        $warnings = [];

        foreach ($spans as $span) {
            $own[$span->name] = ($own[$span->name] ?? 0.0) + ($span->duration() ?? 0.0);
            $classes[$span->name] ??= $span->meta['class'] ?? null;
        }

        foreach ($own as $name => $ms) {
            if ($this->isSlow($ms, $classes[$name] ?? null)) {
                $warnings[] = sprintf('Middleware %s spent %.1f ms of its own time.', $name, $ms);
            }
        }

        foreach ($requests as $span) {
            if (($span->meta['passed'] ?? true) !== false) {
                continue;
            }

            $warnings[] = $this->shortCircuitWarning($span);
        }

        return Summary::make()
            ->headline('middleware_self_ms', round(array_sum($own), 3), 'middleware time', Unit::Milliseconds)
            ->headline('middleware_count', count($requests), 'middleware')
            ->warnings($warnings);
    }

    private function shortCircuitWarning(Span $span): string
    {
        $status = $span->meta['short_circuited_with'] ?? 'no response';
        $threw = $span->meta['threw'] ?? null;

        if ($threw === null) {
            return sprintf('Middleware %s short-circuited the request (%s).', $span->name, $status);
        }

        return sprintf('Middleware %s short-circuited the request by throwing %s (%s).', $span->name, $threw, $status);
    }

    private function isSlow(float $ms, mixed $class): bool
    {
        if ($ms < $this->slowMs) {
            return false;
        }

        return ! $this->isLibrary($class);
    }

    /**
     * Middleware shipped by a package; its time is not the application's to fix.
     */
    private function isLibrary(mixed $class): bool
    {
        if (! is_string($class)) {
            return false;
        }

        if (! class_exists($class)) {
            return false;
        }

        $file = new ReflectionClass($class)->getFileName();

        return is_string($file) && str_contains(str_replace('\\', '/', $file), '/vendor/');
    }

    private function wrap(string $class, string $group): void
    {
        if (isset($this->wrapped[$class])) {
            return;
        }

        if (! class_exists($class)) {
            return;
        }

        $this->wrapped[$class] = true;

        $this->app->extend($class, fn (object $inner): TimedMiddleware => new TimedMiddleware(
            // Unwrap first, so a middleware is never timed twice.
            inner: $inner instanceof TimedMiddleware ? $inner->inner() : $inner,
            class: $class,
            group: $group,
            eyepiece: $this->eyepiece,
        ));
    }

    /**
     * @return list<class-string>
     */
    private function resolveNames(mixed $middleware): array
    {
        if ($middleware instanceof Closure) {
            return [];
        }

        if (! $this->kernelHas('getMiddlewareAliases')) {
            return is_string($middleware) ? [Str::before($middleware, ':')] : [];
        }

        $resolved = MiddlewareNameResolver::resolve(
            $middleware,
            $this->kernel->getMiddlewareAliases(),
            $this->kernel->getMiddlewareGroups(),
        );

        $names = [];

        foreach ((array) $resolved as $name) {
            if (is_string($name)) {
                $names[] = Str::before($name, ':');
            }
        }

        return $names;
    }

    /**
     * @return list<class-string>
     */
    private function globalMiddleware(): array
    {
        if (! $this->kernelHas('getGlobalMiddleware')) {
            return [];
        }

        // Closures in the global stack cannot be wrapped.
        $classes = array_filter($this->kernel->getGlobalMiddleware(), 'is_string');

        return array_values($classes);
    }

    private function kernelHas(string $method): bool
    {
        if ($this->kernel === null) {
            return false;
        }

        return method_exists($this->kernel, $method);
    }

    private function resolveKernel(Application $app): ?Kernel
    {
        try {
            return $app->bound(Kernel::class) ? $app->make(Kernel::class) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.middleware')
            ->columns(['middleware', 'group', 'request_ms' => 'Before', 'response_ms' => 'After', 'passed', 'duration' => 'Own time'])
            ->detailsOnly(['parameters', 'short_circuited_with', 'threw', 'slow'])
            ->groupBy(['group' => 'by group'])
            ->format(['passed' => ['flag', 'passed', 'short-circuited', 'warn']])
            ->highlight(['slow' => 'warn']);
    }
}
