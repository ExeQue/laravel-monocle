<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Exceptions;

use ExeQue\Monocle\Lenses\Packages\GraphQL\GraphQLHook;
use InvalidArgumentException;

class InvalidGraphQLHookException extends InvalidArgumentException implements MonocleExceptionInterface
{
    public static function forClass(string $class): self
    {
        return new self("GraphQL hook [{$class}] must implement ".GraphQLHook::class.'.');
    }
}
