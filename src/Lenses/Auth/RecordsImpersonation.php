<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Auth;

use Closure;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * What every impersonation package adds to the auth section: a row when an
 * impersonation starts and ends, and the impersonator on the authenticated
 * row while one lasts, read from the session so no user is loaded. Use it
 * in a lens that extends AuthLens and has an `$eyepiece` property, and call
 * recordImpersonation() from boot().
 *
 * @property-read Eyepiece $eyepiece
 */
trait RecordsImpersonation
{
    /**
     * @param  class-string  $started  event with $impersonator and $impersonated
     * @param  class-string  $ended  event with $impersonator and $impersonated
     * @param  Closure(): ?array{id: mixed, guard?: string|null}  $impersonator  who is impersonating now, from the session
     */
    private function recordImpersonation(string $started, string $ended, Closure $impersonator): void
    {
        if (class_exists($started)) {
            $this->eyepiece->listen($started, fn (object $event) => $this->pushImpersonation('impersonation_started', $event));
        }

        if (class_exists($ended)) {
            $this->eyepiece->listen($ended, fn (object $event) => $this->pushImpersonation('impersonation_ended', $event));
        }

        $this->eyepiece->modify('auth', function (array $row) use ($impersonator): array {
            if (($row['event'] ?? null) !== 'authenticated') {
                return $row;
            }

            try {
                $by = $impersonator();
            } catch (Throwable) {
                // No session to look in, such as an API guard.
                return $row;
            }

            if ($by === null) {
                return $row;
            }

            // Leaving an impersonation logs the impersonator back in before the session forgets them.
            if (str_ends_with((string) ($row['user'] ?? ''), '#'.$by['id'])) {
                return $row;
            }

            return [...$row, 'impersonated_by' => '#'.$by['id'].(empty($by['guard']) ? '' : ' ('.$by['guard'].' guard)')];
        });
    }

    private function pushImpersonation(string $event, object $fired): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->eyepiece->push('auth', [
            'event' => $event,
            'guard' => null,
            'user' => $this->describeUser($fired->impersonated ?? null),
            'impersonated_by' => $this->describeUser($fired->impersonator ?? null),
        ]);
    }

    private function describeUser(mixed $user): ?string
    {
        return $user instanceof Authenticatable ? $user::class.'#'.$user->getAuthIdentifier() : null;
    }

    public function summarize(Capture $capture): Summary
    {
        $impersonator = null;

        foreach ($capture->rows('auth') as $row) {
            if (($row['event'] ?? null) === 'authenticated' && isset($row['impersonated_by'])) {
                $impersonator = $row['impersonated_by'];
            }
        }

        return Summary::make()
            ->when($impersonator !== null)
            ->total('auth_impersonated_by', $impersonator ?? '', 'impersonated by');
    }
}
