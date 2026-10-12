<?php

declare(strict_types=1);

namespace Tests\Fixtures\GraphQL;

/**
 * What a made-up GraphQL server fires after each operation.
 */
final readonly class OperationRan
{
    /**
     * @param  list<array<string, mixed>>  $errors
     */
    public function __construct(
        public string $query,
        public array $errors = [],
        public bool $parsed = true,
    ) {
    }
}
