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

/**
 * Who the request was authenticated as and how: the guard that resolved the
 * user, logins, logouts, failed attempts and lockouts. Packages such as
 * Fortify, Sanctum and impersonation add to it through their own lenses.
 * Credentials are recorded as keys only.
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
        return 'Authentication: event (authenticated, attempting, login, failed, lockout, logout, logout_current_device, logout_other_devices, registered, verified, password_reset), guard, user (Class#id), remember and the credential keys tried (never values).';
    }

    public function advice(): string
    {
        return 'Look here to see who the request ran as and how, and for failed logins and lockouts.';
    }

    public function boot(): void
    {
        $this->eyepiece->listen(Authenticated::class, fn (Authenticated $event) => $this->push('authenticated', $event->guard, $event->user));
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
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('auth');

        if ($rows === []) {
            return Summary::make();
        }

        $counts = array_count_values(array_column($rows, 'event'));
        $user = null;

        foreach ($rows as $row) {
            if (in_array($row['event'], ['authenticated', 'login'], true) && $row['user'] !== null) {
                $user = $row['user'];
            }
        }

        return Summary::make()
            ->when($user !== null)
            ->total('auth_user', $user, 'user')
            ->when(isset($counts['failed']))
            ->total('auth_failed', $counts['failed'] ?? 0, 'failed logins')
            ->when(isset($counts['lockout']))
            ->total('auth_lockouts', $counts['lockout'] ?? 0, 'lockouts')
            ->when(isset($counts['lockout']))
            ->warning('A login was locked out after too many attempts.');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
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
