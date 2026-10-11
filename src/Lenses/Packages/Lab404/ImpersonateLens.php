<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Lab404;

use ExeQue\Monocle\Lenses\Auth\ImpersonationLens;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

/**
 * Impersonation with lab404/laravel-impersonate, on the auth section.
 */
final class ImpersonateLens extends ImpersonationLens
{
    public function key(): string
    {
        return 'auth_impersonate';
    }

    public static function packages(): array
    {
        return ['lab404/laravel-impersonate'];
    }

    protected function startedEvent(): string
    {
        return TakeImpersonation::class;
    }

    protected function endedEvent(): string
    {
        return LeaveImpersonation::class;
    }

    protected function impersonator(): ?array
    {
        $key = (string) config('laravel-impersonate.session_key', 'impersonated_by');
        $guardKey = (string) config('laravel-impersonate.session_guard', 'impersonator_guard');

        return session()->has($key)
            ? ['id' => session($key), 'guard' => session($guardKey)]
            : null;
    }
}
