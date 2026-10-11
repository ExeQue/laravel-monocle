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

/**
 * Two-factor authentication and password changes with Laravel Fortify, on
 * the auth section. Recovery codes are never recorded.
 */
final class FortifyLens implements ExtendsLens, Lens, RequiresPackages, Summarizable
{
    /** Fortify event => auth row event. */
    private const array EVENTS = [
        'Laravel\Fortify\Events\TwoFactorAuthenticationChallenged' => '2fa_challenged',
        'Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided' => '2fa_passed',
        'Laravel\Fortify\Events\TwoFactorAuthenticationFailed' => '2fa_failed',
        'Laravel\Fortify\Events\TwoFactorAuthenticationEnabled' => '2fa_enabled',
        'Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed' => '2fa_confirmed',
        'Laravel\Fortify\Events\TwoFactorAuthenticationDisabled' => '2fa_disabled',
        'Laravel\Fortify\Events\RecoveryCodesGenerated' => 'recovery_codes_generated',
        'Laravel\Fortify\Events\RecoveryCodeReplaced' => 'recovery_code_used',
        'Laravel\Fortify\Events\PasswordUpdatedViaController' => 'password_updated',
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
