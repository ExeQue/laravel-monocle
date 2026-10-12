<?php

declare(strict_types=1);

namespace Tests\Fixtures\GraphQL;

use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;

/**
 * A hook for a made-up GraphQL server that fires OperationRan.
 */
final readonly class CustomHook implements GraphQLHook
{
    public function __construct(
        private Eyepiece $eyepiece,
    ) {
    }

    public function hook(GraphQLLens $lens): void
    {
        $this->eyepiece->listen(OperationRan::class, function (OperationRan $event) use ($lens): void {
            if (! $event->parsed) {
                $lens->failed('custom', 'public', $event->query, null, null, $event->errors, $this->eyepiece->now());

                return;
            }

            $pending = $lens->start('custom', 'public', $event->query, null, null);

            if ($pending !== null) {
                $lens->finish($pending, $event->errors);
            }
        });
    }
}
