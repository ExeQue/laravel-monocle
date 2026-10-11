<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;
use Lab404\Impersonate\Services\ImpersonateManager;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Throwable;

/**
 * Who the request was authenticated as and how: the guard that resolved the
 * user, logins, logouts, failed attempts and lockouts, and the Sanctum token
 * when one was used. Credentials are recorded as keys only.
 */
final class AuthLens implements Advises, Lens, Presentable, Summarizable
{
    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'auth';
    }

    public function description(): string
    {
        return 'Authentication: event (authenticated, attempting, login, failed, lockout, logout, registered, verified, password_reset, token, and with Fortify 2fa_challenged, 2fa_passed, 2fa_failed, 2fa_enabled, 2fa_confirmed, 2fa_disabled, recovery_codes_generated, recovery_code_used, password_updated, and with lab404/laravel-impersonate impersonation_started, impersonation_ended), guard, user (Class#id), impersonated_by (who is impersonating the user), remember, the credential keys tried (never values) and, for Sanctum, the token id, name and abilities. Recovery codes are never recorded.';
    }

    public function advice(): string
    {
        return 'Look here to see who the request ran as and how, and for failed logins and lockouts.';
    }

    public function boot(): void
    {
        $this->eyepiece->listen(Authenticated::class, fn (Authenticated $event) => $this->push('authenticated', $event->guard, $event->user, $this->impersonation()));
        $this->eyepiece->listen(Attempting::class, fn (Attempting $event) => $this->push('attempting', $event->guard, null, ['remember' => $event->remember, 'credentials' => array_keys($event->credentials)]));
        $this->eyepiece->listen(Login::class, fn (Login $event) => $this->push('login', $event->guard, $event->user, ['remember' => $event->remember]));
        $this->eyepiece->listen(Failed::class, fn (Failed $event) => $this->push('failed', $event->guard, $event->user, ['credentials' => array_keys($event->credentials)]));
        $this->eyepiece->listen(Lockout::class, fn (Lockout $event) => $this->push('lockout', null, null, ['ip' => $event->request->ip()]));
        $this->eyepiece->listen(Logout::class, fn (Logout $event) => $this->push('logout', $event->guard, $event->user));
        $this->eyepiece->listen(CurrentDeviceLogout::class, fn (CurrentDeviceLogout $event) => $this->push('logout_current_device', $event->guard, $event->user));
        $this->eyepiece->listen(OtherDeviceLogout::class, fn (OtherDeviceLogout $event) => $this->push('logout_other_devices', $event->guard, $event->user));
        $this->eyepiece->listen(Registered::class, fn (Registered $event) => $this->push('registered', null, $event->user));
        $this->eyepiece->listen(Verified::class, fn (Verified $event) => $this->push('verified', null, $event->user));
        $this->eyepiece->listen(PasswordReset::class, fn (PasswordReset $event) => $this->push('password_reset', null, $event->user));

        if (class_exists(TokenAuthenticated::class)) {
            $this->eyepiece->listen(TokenAuthenticated::class, function (object $event): void {
                $token = $event->token ?? null;

                $this->push('token', 'sanctum', null, [
                    'token_id' => $token?->getKey(),
                    'token_name' => $token->name ?? null,
                    'abilities' => $token->abilities ?? null,
                    'owner' => isset($token->tokenable_type) ? $token->tokenable_type.'#'.$token->tokenable_id : null,
                ]);
            });
        }

        if (class_exists(TakeImpersonation::class)) {
            $this->eyepiece->listen(TakeImpersonation::class, fn (object $event) => $this->push('impersonation_started', null, $event->impersonated, ['impersonated_by' => $this->describe($event->impersonator)]));
            $this->eyepiece->listen(LeaveImpersonation::class, fn (object $event) => $this->push('impersonation_ended', null, $event->impersonated, ['impersonated_by' => $this->describe($event->impersonator)]));
        }

        $fortify = [
            TwoFactorAuthenticationChallenged::class => '2fa_challenged',
            ValidTwoFactorAuthenticationCodeProvided::class => '2fa_passed',
            TwoFactorAuthenticationFailed::class => '2fa_failed',
            TwoFactorAuthenticationEnabled::class => '2fa_enabled',
            TwoFactorAuthenticationConfirmed::class => '2fa_confirmed',
            TwoFactorAuthenticationDisabled::class => '2fa_disabled',
            RecoveryCodesGenerated::class => 'recovery_codes_generated',
            RecoveryCodeReplaced::class => 'recovery_code_used',
            PasswordUpdatedViaController::class => 'password_updated',
        ];

        foreach ($fortify as $class => $event) {
            if (class_exists($class)) {
                $this->eyepiece->listen($class, fn (object $fired) => $this->push($event, null, $fired->user instanceof Authenticatable ? $fired->user : null));
            }
        }
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('auth');

        if ($rows === []) {
            return Summary::make();
        }

        $counts = array_count_values(array_column($rows, 'event'));
        $user = null;
        $impersonator = null;

        foreach ($rows as $row) {
            if (in_array($row['event'], ['authenticated', 'login'], true) && $row['user'] !== null) {
                $user = $row['user'];
                $impersonator = $row['impersonated_by'] ?? null;
            }
        }

        return Summary::make()
            ->when($user !== null)
            ->total('auth_user', $user, 'user')
            ->when($impersonator !== null)
            ->total('auth_impersonated_by', $impersonator, 'impersonated by')
            ->when(isset($counts['failed']))
            ->total('auth_failed', $counts['failed'] ?? 0, 'failed logins')
            ->when(isset($counts['lockout']))
            ->total('auth_lockouts', $counts['lockout'] ?? 0, 'lockouts')
            ->when(isset($counts['2fa_failed']))
            ->total('auth_2fa_failed', $counts['2fa_failed'] ?? 0, 'failed 2FA challenges')
            ->when(isset($counts['lockout']))
            ->warning('A login was locked out after too many attempts.');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    /**
     * Who is impersonating the authenticated user, when lab404/laravel-impersonate
     * says the session is an impersonation: the impersonator's id and guard.
     *
     * @return array{impersonated_by?: string}
     */
    private function impersonation(): array
    {
        if (! class_exists(ImpersonateManager::class)) {
            return [];
        }

        try {
            $manager = app(ImpersonateManager::class);

            if (! $manager->isImpersonating()) {
                return [];
            }

            return ['impersonated_by' => '#'.$manager->getImpersonatorId().' ('.$manager->getImpersonatorGuardName().' guard)'];
        } catch (Throwable) {
            // No session to look in, such as an API guard.
            return [];
        }
    }

    private function describe(?Authenticatable $user): ?string
    {
        return $user === null ? null : $user::class.'#'.$user->getAuthIdentifier();
    }

    private function push(string $event, ?string $guard, ?Authenticatable $user, array $extra = []): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->eyepiece->push('auth', [
            'event' => $event,
            'guard' => $guard,
            'user' => $this->describe($user),
            ...$extra,
        ], origin: $event !== 'authenticated');
    }

    public function presentation(): Presentation
    {
        return Presentation::table()->tab('monocle::tabs.auth');
    }
}
