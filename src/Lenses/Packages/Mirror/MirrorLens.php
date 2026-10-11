<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Mirror;

use ExeQue\Monocle\Lenses\Auth\ImpersonationLens;
use Mirror\Events\ImpersonationStarted;
use Mirror\Events\ImpersonationStopped;

/**
 * Impersonation with franbarbalopez/mirror, on the auth section.
 */
final class MirrorLens extends ImpersonationLens
{
    public function key(): string
    {
        return 'auth_mirror';
    }

    public static function packages(): array
    {
        return ['franbarbalopez/mirror'];
    }

    protected function startedEvent(): string
    {
        return ImpersonationStarted::class;
    }

    protected function endedEvent(): string
    {
        return ImpersonationStopped::class;
    }

    protected function impersonator(): ?array
    {
        $payload = session(config('mirror.session.key', 'mirror.impersonation').'.payload');

        return is_array($payload) && isset($payload['impersonator_id'])
            ? ['id' => $payload['impersonator_id'], 'guard' => $payload['impersonator_guard'] ?? null]
            : null;
    }
}
