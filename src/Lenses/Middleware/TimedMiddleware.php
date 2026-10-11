<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Middleware;

use Closure;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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

        // Handing the request on ends the "before" span; the "after" span starts once the rest of the pipeline returned.
        $handOn = function (mixed $passable) use ($next, $name, $before, &$after, &$passed): mixed {
            $passed = true;
            $before->stop();

            try {
                return $next($passable);
            } finally {
                $after = $this->eyepiece->span($name, 'middleware.response');
                $after->meta(['class' => $this->class, 'group' => $this->group]);
                $this->eyepiece->startSpan($after, origin: false);
            }
        };

        $shortCircuitedWith = null;
        $threw = null;

        try {
            $response = $this->inner->handle($request, $handOn, ...$parameters);

            if (! $passed && $response instanceof Response) {
                $shortCircuitedWith = $response->getStatusCode();
            }

            return $response;
        } catch (Throwable $e) {
            // Only an exception from the middleware itself stops the request here; one from further in passes through it.
            if (! $passed) {
                $shortCircuitedWith = $this->statusOf($e);
                $threw = $e::class;
            }

            throw $e;
        } finally {
            $before->stop([
                'passed' => $passed,
                'short_circuited_with' => $shortCircuitedWith,
                'threw' => $threw,
            ]);
            $after?->stop();
        }
    }

    /**
     * The status the exception handler turns the exception into.
     */
    private function statusOf(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        if ($e instanceof AuthorizationException) {
            return $e->status() ?? 403;
        }

        if ($e instanceof AuthenticationException) {
            return 401;
        }

        if ($e instanceof ValidationException) {
            return $e->status;
        }

        return 500;
    }

    public function terminate(mixed $request, mixed $response): void
    {
        if (! method_exists($this->inner, 'terminate')) {
            return;
        }

        $name = Str::afterLast($this->class, '\\').'::terminate';

        $span = $this->eyepiece->span($name, 'middleware.terminate');
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
