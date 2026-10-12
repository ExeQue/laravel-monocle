<?php

declare(strict_types=1);

namespace Tests\Fixtures\GraphQL\Queries;

/**
 * A Lighthouse resolver found by the field's name.
 */
final class Ping
{
    public function __invoke(): string
    {
        return 'pong';
    }
}
