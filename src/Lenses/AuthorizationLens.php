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
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;

/**
 * Every gate and policy check with its outcome, so a 403 explains itself.
 */
final class AuthorizationLens implements Advises, Lens, Presentable, Summarizable
{
    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

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

    public function boot(Application $app): void
    {
        $app->make(Gate::class)->after($this->eyepiece->wrap($this->onChecked(...)));
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    private function onChecked(?Authenticatable $user, string $ability, mixed $result, array $arguments): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $this->eyepiece->push('authorization', [
            'ability' => $ability,
            'allowed' => $this->allowed($result),
            'user' => $user === null ? null : $user::class.'#'.$user->getAuthIdentifier(),
            'arguments' => Normalizer::normalize($arguments),
            'message' => $result instanceof Response ? $result->message() : null,
        ]);
    }

    /**
     * A null result means no gate or policy answered the check.
     */
    private function allowed(mixed $result): ?bool
    {
        if ($result instanceof Response) {
            return $result->allowed();
        }

        if ($result === null) {
            return null;
        }

        return (bool) $result;
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('authorization');

        if ($rows === []) {
            return Summary::make();
        }

        $denied = array_values(array_filter($rows, fn (array $row): bool => $row['allowed'] === false));
        $warnings = [];

        foreach (array_slice($denied, 0, 3) as $row) {
            $warnings[] = sprintf('Authorization denied: %s for %s', $row['ability'], $row['user'] ?? 'guest');
        }

        return Summary::make()
            ->total('authorization_checks', count($rows), 'checks')
            ->total('authorization_denied', count($denied), 'denied')
            ->warnings($warnings);
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.authorization')
            ->groupBy(['ability' => 'by ability', 'allowed' => 'by outcome'])
            ->format(['allowed' => ['flag', 'allowed', 'denied']]);
    }
}
