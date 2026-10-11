<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Laravel;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Auth\RecordsImpersonation;
use ExeQue\Monocle\Lenses\AuthLens;
use Laravel\Nova\Events\StartedImpersonating;
use Laravel\Nova\Events\StoppedImpersonating;

/**
 * Impersonation with Laravel Nova, on the auth section.
 */
final class NovaLens implements ExtendsLens, Lens, RequiresPackages, Summarizable
{
    use RecordsImpersonation;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'auth_nova';
    }

    public static function packages(): array
    {
        return ['laravel/nova'];
    }

    public static function extends(): string
    {
        return AuthLens::class;
    }

    public function description(): string
    {
        return 'With Laravel Nova: impersonation_started and impersonation_ended rows, and impersonated_by (the impersonator id and guard) on the authenticated row while an impersonation lasts.';
    }

    public function boot(): void
    {
        $this->recordImpersonation(
            StartedImpersonating::class,
            StoppedImpersonating::class,
            fn (): ?array => session()->has('nova_impersonated_by')
                ? ['id' => session('nova_impersonated_by'), 'guard' => session('nova_impersonated_guard', config('nova.guard'))]
                : null,
        );
    }
}
