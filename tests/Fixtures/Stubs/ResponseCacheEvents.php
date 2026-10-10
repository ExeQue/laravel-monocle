<?php

declare(strict_types=1);

namespace Spatie\ResponseCache\Events;

use Illuminate\Http\Request;

/*
 * Stand-ins for spatie/laravel-responsecache's events, so the lens can be
 * tested without the package. Same constructors as the real ones.
 */

class ResponseCacheHitEvent
{
    public function __construct(public Request $request, public ?int $ageInSeconds = null, public ?array $tags = null)
    {
    }
}

class CacheMissedEvent
{
    public function __construct(public Request $request)
    {
    }
}

class ClearingResponseCacheEvent
{
}

class ClearedResponseCacheEvent
{
}

class ClearingResponseCacheFailedEvent
{
}
