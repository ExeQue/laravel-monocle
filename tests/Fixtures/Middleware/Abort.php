<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middleware;

use Closure;

final class Abort
{
    public function handle(mixed $request, Closure $next): mixed
    {
        return response('nope', 418);
    }
}
