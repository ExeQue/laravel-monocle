<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Mirror;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Auth\RecordsImpersonation;
use ExeQue\Monocle\Lenses\AuthLens;

/**
 * Impersonation with franbarbalopez/mirror, on the auth section.
 */
final class MirrorLens implements ExtendsLens, Lens, RequiresPackages, Summarizable
{
    use RecordsImpersonation;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'auth_mirror';
    }

    public static function packages(): array
    {
        return ['franbarbalopez/mirror'];
    }

    public static function extends(): string
    {
        return AuthLens::class;
    }

    public function description(): string
    {
        return 'With franbarbalopez/mirror: impersonation_started and impersonation_ended rows, and impersonated_by (the impersonator id and guard) on the authenticated row while an impersonation lasts.';
    }

    public function boot(): void
    {
        $this->recordImpersonation(
            'Mirror\Events\ImpersonationStarted',
            'Mirror\Events\ImpersonationStopped',
            function (): ?array {
                $payload = session(config('mirror.session.key', 'mirror.impersonation').'.payload');

                return is_array($payload) && isset($payload['impersonator_id'])
                    ? ['id' => $payload['impersonator_id'], 'guard' => $payload['impersonator_guard'] ?? null]
                    : null;
            },
        );
    }
}
