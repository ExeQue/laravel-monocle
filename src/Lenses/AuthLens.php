<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
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
use Illuminate\Contracts\Foundation\Application;
use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * Who the request was authenticated as and how: the guard that resolved the
 * user, logins, logouts, failed attempts and lockouts, and the Sanctum token
 * when one was used. Credentials are recorded as keys only.
 */
final class AuthLens implements Advises, Lens, Summarizable
{
    private Eyepiece $eyepiece;

    public function key(): string
    {
        return 'auth';
    }

    public function description(): string
    {
        return 'Authentication: event (authenticated, attempting, login, failed, lockout, logout, registered, verified, password_reset, token), guard, user (Class#id), remember, the credential keys tried (never values) and, for Sanctum, the token id, name and abilities.';
    }

    public function advice(): string
    {
        return 'Look here to see who the request ran as and how, and for failed logins and lockouts.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $eyepiece->listen(Authenticated::class, fn (Authenticated $event) => $this->push('authenticated', $event->guard, $event->user));
        $eyepiece->listen(Attempting::class, fn (Attempting $event) => $this->push('attempting', $event->guard, null, ['remember' => $event->remember, 'credentials' => array_keys($event->credentials)]));
        $eyepiece->listen(Login::class, fn (Login $event) => $this->push('login', $event->guard, $event->user, ['remember' => $event->remember]));
        $eyepiece->listen(Failed::class, fn (Failed $event) => $this->push('failed', $event->guard, $event->user, ['credentials' => array_keys($event->credentials)]));
        $eyepiece->listen(Lockout::class, fn (Lockout $event) => $this->push('lockout', null, null, ['ip' => $event->request->ip()]));
        $eyepiece->listen(Logout::class, fn (Logout $event) => $this->push('logout', $event->guard, $event->user));
        $eyepiece->listen(CurrentDeviceLogout::class, fn (CurrentDeviceLogout $event) => $this->push('logout_current_device', $event->guard, $event->user));
        $eyepiece->listen(OtherDeviceLogout::class, fn (OtherDeviceLogout $event) => $this->push('logout_other_devices', $event->guard, $event->user));
        $eyepiece->listen(Registered::class, fn (Registered $event) => $this->push('registered', null, $event->user));
        $eyepiece->listen(Verified::class, fn (Verified $event) => $this->push('verified', null, $event->user));
        $eyepiece->listen(PasswordReset::class, fn (PasswordReset $event) => $this->push('password_reset', null, $event->user));

        if (class_exists(TokenAuthenticated::class)) {
            $eyepiece->listen(TokenAuthenticated::class, function (object $event): void {
                $token = $event->token ?? null;

                $this->push('token', 'sanctum', null, [
                    'token_id' => $token?->getKey(),
                    'token_name' => $token->name ?? null,
                    'abilities' => $token->abilities ?? null,
                    'owner' => isset($token->tokenable_type) ? $token->tokenable_type.'#'.$token->tokenable_id : null,
                ]);
            });
        }
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('auth');

        if ($rows === []) {
            return [];
        }

        $counts = array_count_values(array_column($rows, 'event'));
        $user = null;

        foreach ($rows as $row) {
            if (in_array($row['event'], ['authenticated', 'login'], true) && $row['user'] !== null) {
                $user = $row['user'];
            }
        }

        return [
            'totals' => array_filter([
                'auth_user' => $user,
                'auth_failed' => $counts['failed'] ?? null,
                'auth_lockouts' => $counts['lockout'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            'warnings' => isset($counts['lockout']) ? ['A login was locked out after too many attempts.'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function push(string $event, ?string $guard, ?Authenticatable $user, array $extra = []): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->eyepiece->push('auth', [
            'event' => $event,
            'guard' => $guard,
            'user' => $user === null ? null : $user::class.'#'.$user->getAuthIdentifier(),
            ...$extra,
        ], origin: $event !== 'authenticated');
    }
}
