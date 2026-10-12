<?php

declare(strict_types=1);

namespace Tests\Fixtures\GraphQL;

use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLHook;
use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLLens;
use RuntimeException;

/**
 * A hook for a server that is not installed; it must never be hooked in.
 */
final class MissingPackageHook implements GraphQLHook, RequiresPackages
{
    public static function packages(): array
    {
        return ['acme/not-installed'];
    }

    public function hook(GraphQLLens $lens): void
    {
        throw new RuntimeException('hooked in without its package');
    }
}
