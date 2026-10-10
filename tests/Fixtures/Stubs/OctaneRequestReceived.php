<?php

declare(strict_types=1);

namespace Laravel\Octane\Events;

use Illuminate\Http\Request;

/**
 * Stand-in for Octane's event, so the request lens can be tested without the package.
 */
class RequestReceived
{
    public function __construct(public mixed $app, public mixed $sandbox, public Request $request)
    {
    }
}
