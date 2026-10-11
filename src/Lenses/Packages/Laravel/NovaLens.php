<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Laravel;

use ExeQue\Monocle\Lenses\Auth\ImpersonationLens;
use Laravel\Nova\Events\StartedImpersonating;
use Laravel\Nova\Events\StoppedImpersonating;

/**
 * Impersonation with Laravel Nova, on the auth section.
 */
final class NovaLens extends ImpersonationLens
{
    public function key(): string
    {
        return 'auth_nova';
    }

    public static function packages(): array
    {
        return ['laravel/nova'];
    }

    protected function startedEvent(): string
    {
        return StartedImpersonating::class;
    }

    protected function endedEvent(): string
    {
        return StoppedImpersonating::class;
    }

    protected function impersonator(): ?array
    {
        return session()->has('nova_impersonated_by')
            ? ['id' => session('nova_impersonated_by'), 'guard' => session('nova_impersonated_guard', config('nova.guard'))]
            : null;
    }
}
