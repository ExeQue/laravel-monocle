<?php

declare(strict_types=1);

namespace Workbench\App\GraphQL;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Query;

final class BoomQuery extends Query
{
    /** @var array<string, string> */
    protected $attributes = ['name' => 'boom'];

    public function type(): Type
    {
        return Type::string();
    }

    public function resolve(): string
    {
        return new Resolvers()->boom();
    }
}
