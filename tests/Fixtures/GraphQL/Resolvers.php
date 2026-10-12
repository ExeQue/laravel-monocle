<?php

declare(strict_types=1);

namespace Tests\Fixtures\GraphQL;

use RuntimeException;

/**
 * Resolvers shared by the Lighthouse schema and the rebing fields.
 */
final class Resolvers
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function hello(mixed $root, array $args): string
    {
        return 'Hello '.($args['name'] ?? 'world');
    }

    public function boom(): never
    {
        throw new RuntimeException('resolver exploded');
    }

    public function login(): bool
    {
        return true;
    }
}
