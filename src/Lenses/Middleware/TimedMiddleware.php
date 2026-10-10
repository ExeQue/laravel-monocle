<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Middleware;

use Closure;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decorates a middleware instance resolved from the container and records a
 * span around handle(), with a "before" child span for the work done before
 * $next and an "after" child span for the work done once $next returned.
 */
final class TimedMiddleware
{
    public function __construct(
        private readonly object $inner,
        private readonly string $class,
        private readonly string $group,
        private readonly Eyepiece $eyepiece,
    ) {
    }

    public function handle(mixed $request, Closure $next, mixed ...$parameters): mixed
    {
        $span = $this->eyepiece->span(Str::afterLast($this->class, '\\'), 'middleware');
        $span->meta([
            'class' => $this->class,
            'parameters' => $parameters,
            'group' => $this->group,
        ]);
        $this->eyepiece->startSpan($span, origin: false);

        $before = $this->eyepiece->span('before', 'middleware.before');
        $this->eyepiece->startSpan($before, origin: false);

        $after = null;
        $passed = false;
        $nextCalledAt = null;
        $nextReturnedAt = null;
        $response = null;

        try {
            $response = $this->inner->handle($request, function (mixed $passable) use ($next, $before, &$after, &$passed, &$nextCalledAt, &$nextReturnedAt): mixed {
                $passed = true;
                $before->stop();
                $nextCalledAt = $this->eyepiece->now();

                try {
                    return $next($passable);
                } finally {
                    $nextReturnedAt = $this->eyepiece->now();
                    $after = $this->eyepiece->span('after', 'middleware.after');
                    $this->eyepiece->startSpan($after, origin: false);
                }
            }, ...$parameters);

            return $response;
        } finally {
            $before->stop();
            $after?->stop();
            $end = $this->eyepiece->now();
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

        $span = $this->eyepiece->span(Str::afterLast($this->class, '\\').'::terminate', 'middleware.terminate');
        $span->meta(['class' => $this->class]);
        $this->eyepiece->startSpan($span, origin: false);

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
