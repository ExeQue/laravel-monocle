<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use Closure;
use ExeQue\Monocle\Contracts\Configurable;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\SummarizesEntry;
use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\Lenses\Middleware\TimedMiddleware;
use ExeQue\Monocle\Recorder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Support\Str;
use Throwable;

/**
 * Times every class-based middleware by wrapping it in the container. The
 * pipeline resolves middleware through Container::make(), so extending the
 * binding is enough; RouteMatched fires before the route pipeline is built.
 *
 * ponytail: closure middleware and instances created outside the container
 * are not timed.
 */
final class MiddlewareLens implements Configurable, Lens, SummarizesEntry
{
    private Application $app;

    private Recorder $recorder;

    private ?Kernel $kernel = null;

    /** @var array<class-string, true> */
    private array $wrapped = [];

    /** @var array<class-string, true> */
    private array $global = [];

    private float $slowMs = 50.0;

    public function key(): string
    {
        return 'middleware';
    }

    public function description(): string
    {
        return 'One timeline span per class-based middleware with before_ms (time until it called \$next), after_ms (time after \$next returned), passed (false when it short-circuited) and the HTTP status it returned instead. Use kinds=[middleware] on the timeline.';
    }

    public function configure(array $options): void
    {
        $this->slowMs = (float) ($options['slow_ms'] ?? $this->slowMs);
    }

    public function boot(Application $app, Recorder $recorder): void
    {
        $this->app = $app;
        $this->recorder = $recorder;

        $app->booted(function () use ($app): void {
            $this->kernel = $this->resolveKernel($app);

            foreach ($this->globalMiddleware() as $middleware) {
                $this->global[$middleware] = true;
                $this->wrap($middleware, 'global');
            }
        });

        $app->make(Dispatcher::class)->listen(RouteMatched::class, function (RouteMatched $event): void {
            foreach ($event->route->gatherMiddleware() as $middleware) {
                foreach ($this->resolveNames($middleware) as $class) {
                    $this->wrap($class, isset($this->global[$class]) ? 'global' : 'route');
                }
            }
        });
    }

    public function finalize(Entry $entry): void
    {
    }

    public function reset(): void
    {
    }

    public function summarize(Entry $entry): array
    {
        $spans = $entry->spansOfKind('middleware');
        $self = 0.0;
        $warnings = [];

        foreach ($spans as $span) {
            $own = (float) ($span->meta['before_ms'] ?? 0) + (float) ($span->meta['after_ms'] ?? 0);
            $self += $own;

            if ($own >= $this->slowMs) {
                $warnings[] = sprintf('Middleware %s spent %.1f ms of its own time.', $span->name, $own);
            }

            if (($span->meta['passed'] ?? true) === false) {
                $warnings[] = sprintf('Middleware %s short-circuited the request (%s).', $span->name, $span->meta['short_circuited_with'] ?? 'no response');
            }
        }

        return [
            'totals' => [
                'middleware_count' => count($spans),
                'middleware_self_ms' => round($self, 3),
            ],
            'warnings' => $warnings,
        ];
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
            recorder: $this->recorder,
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
