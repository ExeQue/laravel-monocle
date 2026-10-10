<?php

declare(strict_types=1);

namespace Laravel\Sanctum\Events;

/**
 * Stand-in for Sanctum's event, so the auth lens can be tested without the package.
 */
class TokenAuthenticated
{
    public function __construct(public mixed $token)
    {
    }
}
