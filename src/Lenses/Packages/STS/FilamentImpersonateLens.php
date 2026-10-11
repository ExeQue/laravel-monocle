<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\STS;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Auth\RecordsImpersonation;
use ExeQue\Monocle\Lenses\AuthLens;
use STS\FilamentImpersonate\Events\EnterImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

/**
 * Impersonation with stechstudio/filament-impersonate, on the auth section.
 */
final class FilamentImpersonateLens implements ExtendsLens, Lens, RequiresPackages, Summarizable
{
    use RecordsImpersonation;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'auth_filament_impersonate';
    }

    public static function packages(): array
    {
        return ['stechstudio/filament-impersonate'];
    }

    public static function extends(): string
    {
        return AuthLens::class;
    }

    public function description(): string
    {
        return 'With stechstudio/filament-impersonate: impersonation_started and impersonation_ended rows, and impersonated_by (the impersonator id and guard) on the authenticated row while an impersonation lasts.';
    }

    public function boot(): void
    {
        $this->recordImpersonation(
            EnterImpersonation::class,
            LeaveImpersonation::class,
            fn (): ?array => session()->has('impersonated_by')
                ? ['id' => session('impersonated_by'), 'guard' => session('impersonator_guard')]
                : null,
        );
    }
}
