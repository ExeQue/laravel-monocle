<?php

declare(strict_types=1);

// Laravel Nova is not on Packagist, so its two impersonation events are stood in for here.

namespace Laravel\Nova\Events;

final class StartedImpersonating
{
    public function __construct(
        public mixed $impersonator,
        public mixed $impersonated,
    ) {
    }
}

final class StoppedImpersonating
{
    public function __construct(
        public mixed $impersonator,
        public mixed $impersonated,
    ) {
    }
}
