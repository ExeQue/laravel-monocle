<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middleware;

use Closure;

final class SleepAfter
{
    public static bool $terminated = false;

    public function handle(mixed $request, Closure $next): mixed
    {
        $response = $next($request);

        usleep(5000);

        return $response;
    }

    public function terminate(mixed $request, mixed $response): void
    {
        self::$terminated = true;
    }
}
