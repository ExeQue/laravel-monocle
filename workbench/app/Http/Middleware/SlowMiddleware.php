<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SlowMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        usleep(150_000);

        return $next($request);
    }
}
