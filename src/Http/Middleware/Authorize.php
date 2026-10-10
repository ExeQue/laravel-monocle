<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request into the dashboard only when the viewMonocle gate allows it.
 */
final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::check('viewMonocle', [$request->user()]), 403);

        return $next($request);
    }
}
