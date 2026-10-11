<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;

/**
 * Every gate and policy check with its outcome, so a 403 explains itself.
 */
final class AuthorizationLens implements Advises, Lens, Summarizable
{
    private Eyepiece $eyepiece;

    public function key(): string
    {
        return 'authorization';
    }

    public function description(): string
    {
        return 'Gate and policy checks: ability, result (allowed/denied), the user it was checked for, the arguments (models as Class#id) and the denial message when a policy returned one.';
    }

    public function advice(): string
    {
        return 'Look here to explain a 403 or a hidden button: which ability was checked, for whom, and the answer.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $app->make(Gate::class)->after(function (?Authenticatable $user, string $ability, mixed $result, array $arguments): void {
            if (! $this->eyepiece->recording()) {
                return;
            }

            $allowed = $result instanceof Response ? $result->allowed() : ($result === null ? null : (bool) $result);

            $this->eyepiece->push('authorization', [
                'ability' => $ability,
                'allowed' => $allowed,
                'user' => $user === null ? null : $user::class.'#'.$user->getAuthIdentifier(),
                'arguments' => Normalizer::normalize($arguments),
                'message' => $result instanceof Response ? $result->message() : null,
            ]);
        });
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('authorization');

        if ($rows === []) {
            return [];
        }

        $denied = array_values(array_filter($rows, fn (array $row): bool => $row['allowed'] === false));

        return [
            'totals' => [
                'authorization_checks' => count($rows),
                'authorization_denied' => count($denied),
            ],
            'warnings' => array_map(
                fn (array $row): string => sprintf('Authorization denied: %s for %s', $row['ability'], $row['user'] ?? 'guest'),
                array_slice($denied, 0, 3),
            ),
        ];
    }
}
