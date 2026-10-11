<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Laravel;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\AuthLens;
use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * The Laravel Sanctum token a request authenticated with, on the auth section.
 */
final class SanctumLens implements ExtendsLens, Lens, RequiresPackages
{
    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'auth_sanctum';
    }

    public static function packages(): array
    {
        return ['laravel/sanctum'];
    }

    public static function extends(): string
    {
        return AuthLens::class;
    }

    public function description(): string
    {
        return 'With Laravel Sanctum: a token row with the token id, name, abilities and owner (Class#id) when a request authenticates with a token.';
    }

    public function boot(): void
    {
        // Event missing from older laravel/sanctum versions.
        // @codeCoverageIgnoreStart
        if (! class_exists(TokenAuthenticated::class)) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $this->eyepiece->listen(TokenAuthenticated::class, function (object $event): void {
            if (! $this->eyepiece->recording()) {
                return;
            }

            $token = $event->token ?? null;

            $this->eyepiece->push('auth', [
                'event' => 'token',
                'guard' => 'sanctum',
                'user' => null,
                'token_id' => $token?->getKey(),
                'token_name' => $token->name ?? null,
                'abilities' => $token->abilities ?? null,
                'owner' => isset($token->tokenable_type) ? $token->tokenable_type.'#'.$token->tokenable_id : null,
            ]);
        });
    }
}
