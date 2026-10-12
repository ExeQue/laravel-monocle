<?php

declare(strict_types=1);

namespace Tests\Fixtures\GraphQL;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Query;

final class HelloQuery extends Query
{
    /** @var array<string, string> */
    protected $attributes = ['name' => 'hello'];

    public function type(): Type
    {
        return Type::nonNull(Type::string());
    }

    public function args(): array
    {
        return ['name' => ['type' => Type::string()]];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function resolve(mixed $root, array $args): string
    {
        return new Resolvers()->hello($root, $args);
    }
}
