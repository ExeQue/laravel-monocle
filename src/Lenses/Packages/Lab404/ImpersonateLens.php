<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Lab404;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Auth\RecordsImpersonation;
use ExeQue\Monocle\Lenses\AuthLens;

/**
 * Impersonation with lab404/laravel-impersonate, on the auth section.
 */
final class ImpersonateLens implements ExtendsLens, Lens, RequiresPackages, Summarizable
{
    use RecordsImpersonation;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'auth_impersonate';
    }

    public static function packages(): array
    {
        return ['lab404/laravel-impersonate'];
    }

    public static function extends(): string
    {
        return AuthLens::class;
    }

    public function description(): string
    {
        return 'With lab404/laravel-impersonate: impersonation_started and impersonation_ended rows, and impersonated_by (the impersonator id and guard) on the authenticated row while an impersonation lasts.';
    }

    public function boot(): void
    {
        $this->recordImpersonation(
            'Lab404\Impersonate\Events\TakeImpersonation',
            'Lab404\Impersonate\Events\LeaveImpersonation',
            fn (): ?array => session()->has($key = (string) config('laravel-impersonate.session_key', 'impersonated_by'))
                ? ['id' => session($key), 'guard' => session((string) config('laravel-impersonate.session_guard', 'impersonator_guard'))]
                : null,
        );
    }
}
