<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL;

/**
 * Records the operations of a GraphQL server through the GraphQL lens: call
 * $lens->start() when an operation starts and $lens->finish() with what it
 * started when it ends, or $lens->failed() for one that never started.
 * A hook is resolved from the container. Implement RequiresPackages to be
 * left out where the server's package is not installed, and Resettable to
 * clear what it keeps between captures.
 */
interface GraphQLHook
{
    public function hook(GraphQLLens $lens): void;
}
