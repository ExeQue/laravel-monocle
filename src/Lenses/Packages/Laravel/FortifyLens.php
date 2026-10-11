<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Laravel;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\AuthLens;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Two-factor authentication and password changes with Laravel Fortify, on
 * the auth section. Recovery codes are never recorded.
 */
final class FortifyLens implements ExtendsLens, Lens, RequiresPackages, Summarizable
{
    /** Fortify event => auth row event. */
    private const array EVENTS = [
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

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'auth_fortify';
    }

    public static function packages(): array
    {
        return ['laravel/fortify'];
    }

    public static function extends(): string
    {
        return AuthLens::class;
    }

    public function description(): string
    {
        return 'With Laravel Fortify: 2fa_challenged, 2fa_passed, 2fa_failed, 2fa_enabled, 2fa_confirmed, 2fa_disabled, recovery_codes_generated, recovery_code_used and password_updated rows. Recovery codes are never recorded.';
    }

    public function boot(): void
    {
        foreach (self::EVENTS as $class => $event) {
            if (class_exists($class)) {
                $this->eyepiece->listen($class, fn (object $fired) => $this->push($event, $fired->user ?? null));
            }
        }
    }

    public function summarize(Capture $capture): Summary
    {
        $failed = count(array_filter($capture->rows('auth'), fn (array $row): bool => ($row['event'] ?? null) === '2fa_failed'));

        return Summary::make()
            ->when($failed > 0)
            ->total('auth_2fa_failed', $failed, 'failed 2FA challenges');
    }

    private function push(string $event, mixed $user): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->eyepiece->push('auth', [
            'event' => $event,
            'guard' => null,
            'user' => $user instanceof Authenticatable ? $user::class.'#'.$user->getAuthIdentifier() : null,
        ]);
    }
}
