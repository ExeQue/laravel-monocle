<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middleware;

use Closure;

final class SleepBefore
{
    public function handle(mixed $request, Closure $next, string $ms = '5'): mixed
    {
        usleep((int) $ms * 1000);

        return $next($request);
    }
}
