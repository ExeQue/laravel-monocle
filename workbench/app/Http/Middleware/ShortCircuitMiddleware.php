<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers on its own and never hands the request on, like a maintenance or
 * "must be logged in" middleware that redirects.
 */
final class ShortCircuitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return redirect()->route('home');
    }
}
