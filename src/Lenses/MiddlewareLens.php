<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
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
final class MiddlewareLens implements Advises, Configurable, Lens, Summarizable
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
        return 'Two timeline spans per class-based middleware: middleware.request (the work done before it called $next, with passed=false and the HTTP status when it short-circuited) and middleware.response (the work done once $next returned). Use kinds=[middleware] on the timeline.';
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
            foreach ($event->route->gatherMiddleware() as $middleware) {
                foreach ($this->resolveNames($middleware) as $class) {
                    $this->wrap($class, isset($this->global[$class]) ? 'global' : 'route');
                }
            }
        });
    }

    public function summarize(Capture $capture): array
    {
        $requests = $capture->spansOfKind('middleware.request');
        $own = [];
        $warnings = [];

        $classes = [];

        foreach ([...$requests, ...$capture->spansOfKind('middleware.response')] as $span) {
            $own[$span->name] = ($own[$span->name] ?? 0.0) + ($span->duration() ?? 0.0);
            $classes[$span->name] ??= $span->meta['class'] ?? null;
        }

        foreach ($own as $name => $ms) {
            if ($ms >= $this->slowMs && ! $this->isLibrary($classes[$name] ?? null)) {
                $warnings[] = sprintf('Middleware %s spent %.1f ms of its own time.', $name, $ms);
            }
        }

        foreach ($requests as $span) {
            if (($span->meta['passed'] ?? true) === false) {
                $warnings[] = sprintf('Middleware %s short-circuited the request (%s).', $span->name, $span->meta['short_circuited_with'] ?? 'no response');
            }
        }

        return [
            'totals' => [
                'middleware_count' => count($requests),
                'middleware_self_ms' => round(array_sum($own), 3),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Middleware shipped by a package; its time is not the application's to fix.
     */
    private function isLibrary(mixed $class): bool
    {
        if (! is_string($class) || ! class_exists($class)) {
            return false;
        }

        $file = (new ReflectionClass($class))->getFileName();

        return is_string($file) && str_contains(str_replace('\\', '/', $file), '/vendor/');
    }

    private function wrap(string $class, string $group): void
    {
        if (isset($this->wrapped[$class]) || ! class_exists($class)) {
            return;
        }

        $this->wrapped[$class] = true;

        $this->app->extend($class, fn (object $inner): TimedMiddleware => new TimedMiddleware(
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

        if ($this->kernel === null || ! method_exists($this->kernel, 'getMiddlewareAliases')) {
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
        if ($this->kernel === null || ! method_exists($this->kernel, 'getGlobalMiddleware')) {
            return [];
        }

        return array_values(array_filter($this->kernel->getGlobalMiddleware(), 'is_string'));
    }

    private function resolveKernel(Application $app): ?Kernel
    {
        try {
            return $app->bound(Kernel::class) ? $app->make(Kernel::class) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
