<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Middleware;

use Closure;
use ExeQue\Monocle\Recorder;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decorates a middleware instance resolved from the container and records a
 * span around handle(), splitting the time spent before and after $next.
 */
final class TimedMiddleware
{
    public function __construct(
        private readonly object $inner,
        private readonly string $class,
        private readonly string $group,
        private readonly Recorder $recorder,
    ) {
    }

    public function handle(mixed $request, Closure $next, mixed ...$parameters): mixed
    {
        $span = $this->recorder->span(Str::afterLast($this->class, '\\'), 'middleware');
        $span->meta([
            'class' => $this->class,
            'parameters' => $parameters,
            'group' => $this->group,
        ]);
        $this->recorder->startSpan($span, origin: false);

        $passed = false;
        $nextCalledAt = null;
        $nextReturnedAt = null;
        $response = null;

        try {
            $response = $this->inner->handle($request, function (mixed $passable) use ($next, &$passed, &$nextCalledAt, &$nextReturnedAt): mixed {
                $passed = true;
                $nextCalledAt = $this->recorder->now();

                try {
                    return $next($passable);
                } finally {
                    $nextReturnedAt = $this->recorder->now();
                }
            }, ...$parameters);

            return $response;
        } finally {
            $end = $this->recorder->now();
            $start = $span->start ?? $end;

            $span->stop([
                'passed' => $passed,
                'next_called_at' => $nextCalledAt,
                'next_returned_at' => $nextReturnedAt,
                'before_ms' => round(($nextCalledAt ?? $end) - $start, 3),
                'after_ms' => $nextReturnedAt === null ? 0.0 : round($end - $nextReturnedAt, 3),
                'short_circuited_with' => $passed ? null : ($response instanceof Response ? $response->getStatusCode() : null),
            ]);
        }
    }

    public function terminate(mixed $request, mixed $response): void
    {
        if (! method_exists($this->inner, 'terminate')) {
            return;
        }

        $span = $this->recorder->span(Str::afterLast($this->class, '\\') . '::terminate', 'middleware.terminate');
        $span->meta(['class' => $this->class]);
        $this->recorder->startSpan($span, origin: false);

        try {
            $this->inner->terminate($request, $response);
        } finally {
            $span->stop();
        }
    }

    public function inner(): object
    {
        return $this->inner;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    public function __get(string $name): mixed
    {
        return $this->inner->{$name};
    }
}
