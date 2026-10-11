<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\STS;

use ExeQue\Monocle\Lenses\Auth\ImpersonationLens;
use STS\FilamentImpersonate\Events\EnterImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

/**
 * Impersonation with stechstudio/filament-impersonate, on the auth section.
 */
final class FilamentImpersonateLens extends ImpersonationLens
{
    public function key(): string
    {
        return 'auth_filament_impersonate';
    }

    public static function packages(): array
    {
        return ['stechstudio/filament-impersonate'];
    }

    protected function startedEvent(): string
    {
        return EnterImpersonation::class;
    }

    protected function endedEvent(): string
    {
        return LeaveImpersonation::class;
    }

    protected function impersonator(): ?array
    {
        return session()->has('impersonated_by')
            ? ['id' => session('impersonated_by'), 'guard' => session('impersonator_guard')]
            : null;
    }
}
