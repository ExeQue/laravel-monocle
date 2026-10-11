<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Middleware;

use Closure;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decorates a middleware instance resolved from the container and records two
 * spans: "middleware.request" for the work done before $next, and
 * "middleware.response" for the work done once $next returned.
 *
 * @internal
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
        $name = Str::afterLast($this->class, '\\');

        $before = $this->eyepiece->span($name, 'middleware.request');
        $before->meta(['class' => $this->class, 'parameters' => $parameters, 'group' => $this->group]);
        $this->eyepiece->startSpan($before, origin: false);

        $after = null;
        $passed = false;
        $response = null;

        try {
            $response = $this->inner->handle($request, function (mixed $passable) use ($next, $name, $before, &$after, &$passed): mixed {
                $passed = true;
                $before->stop();

                try {
                    return $next($passable);
                } finally {
                    $after = $this->eyepiece->span($name, 'middleware.response');
                    $after->meta(['class' => $this->class, 'group' => $this->group]);
                    $this->eyepiece->startSpan($after, origin: false);
                }
            }, ...$parameters);

            return $response;
        } finally {
            $before->stop([
                'passed' => $passed,
                'short_circuited_with' => $passed ? null : ($response instanceof Response ? $response->getStatusCode() : null),
            ]);
            $after?->stop();
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
