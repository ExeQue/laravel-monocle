<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middleware;

use Closure;

final class Abort
{
    public function handle(mixed $request, Closure $next, string $status = '418'): mixed
    {
        return response('nope', (int) $status);
    }
}
