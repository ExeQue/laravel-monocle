<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Auth;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\AuthLens;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * What every impersonation package adds to the auth section: a row when an
 * impersonation starts and ends, and the impersonator on the authenticated
 * row while one lasts, read from the session so no user is loaded.
 */
abstract class ImpersonationLens implements ExtendsLens, Lens, RequiresPackages, Summarizable
{
    public function __construct(
        protected readonly Eyepiece $eyepiece,
    ) {
    }

    /**
     * The event fired when an impersonation starts, with `$impersonator` and `$impersonated`.
     *
     * @return class-string
     */
    abstract protected function startedEvent(): string;

    /**
     * The event fired when an impersonation ends, with `$impersonator` and `$impersonated`.
     *
     * @return class-string
     */
    abstract protected function endedEvent(): string;

    /**
     * Who is impersonating now, read from the session; null when nobody is.
     *
     * @return array{id: mixed, guard?: string|null}|null
     */
    abstract protected function impersonator(): ?array;

    public static function extends(): string
    {
        return AuthLens::class;
    }

    public function description(): string
    {
        return 'With '.static::packages()[0].': impersonation_started and impersonation_ended rows, and impersonated_by (the impersonator id and guard) on the authenticated row while an impersonation lasts.';
    }

    public function boot(): void
    {
        $started = $this->startedEvent();
        $ended = $this->endedEvent();

        if (class_exists($started)) {
            $this->eyepiece->listen($started, function (object $event): void {
                $this->pushImpersonation('impersonation_started', $event);
            });
        }

        if (class_exists($ended)) {
            $this->eyepiece->listen($ended, function (object $event): void {
                $this->pushImpersonation('impersonation_ended', $event);
            });
        }

        $this->eyepiece->modify('auth', $this->markImpersonated(...));
    }

    /**
     * Adds impersonated_by to the authenticated row while an impersonation lasts.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function markImpersonated(array $row): array
    {
        if (($row['event'] ?? null) !== 'authenticated') {
            return $row;
        }

        try {
            $by = $this->impersonator();
        } catch (Throwable) {
            // No session to look in, such as an API guard.
            return $row;
        }

        if ($by === null) {
            return $row;
        }

        $impersonatorId = '#'.$by['id'];

        // Leaving an impersonation logs the impersonator back in before the session forgets them.
        if (str_ends_with((string) ($row['user'] ?? ''), $impersonatorId)) {
            return $row;
        }

        $guard = empty($by['guard']) ? '' : ' ('.$by['guard'].' guard)';

        return [...$row, 'impersonated_by' => $impersonatorId.$guard];
    }

    public function summarize(Capture $capture): Summary
    {
        // The impersonator on the last authenticated row.
        $impersonator = collect($capture->rows('auth'))
            ->whereStrict('event', 'authenticated')
            ->whereNotNull('impersonated_by')
            ->pluck('impersonated_by')
            ->last();

        return Summary::make()
            ->when($impersonator !== null)
            ->total('auth_impersonated_by', $impersonator ?? '', 'impersonated by')
            ->when($impersonator !== null)
            ->tag('impersonated by '.$impersonator, 'warn');
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
        if (! $user instanceof Authenticatable) {
            return null;
        }

        return $user::class.'#'.$user->getAuthIdentifier();
    }
}
