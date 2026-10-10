<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Session;

use Closure;
use Throwable;

/**
 * Decorates the StartSession middleware and hands the session contents to a
 * callback as soon as the session has started, before the rest of the request
 * changes it.
 */
final class SnapshotsSession
{
    /**
     * @param  Closure(array<string, mixed>): void  $snapshot
     */
    public function __construct(
        private readonly object $inner,
        private readonly Closure $snapshot,
    ) {
    }

    public function handle(mixed $request, Closure $next, mixed ...$parameters): mixed
    {
        return $this->inner->handle($request, function (mixed $passable) use ($next): mixed {
            try {
                ($this->snapshot)($passable->session()->all());
            } catch (Throwable) {
                // No session after all; nothing to compare against.
            }

            return $next($passable);
        }, ...$parameters);
    }

    public function terminate(mixed $request, mixed $response): void
    {
        if (method_exists($this->inner, 'terminate')) {
            $this->inner->terminate($request, $response);
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
