<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/** @internal */
trait DescribesPagination
{
    /**
     * @return array<string, Type>
     */
    protected function paginationSchema(JsonSchema $schema, int $defaultLimit = 50): array
    {
        return [
            'offset' => $schema->integer()->description('Skip this many rows. Use next_offset from the previous response.'),
            'limit' => $schema->integer()->description("Rows per page (default {$defaultLimit}, max 500)."),
        ];
    }

    /**
     * @return array<string, Type>
     */
    protected function rowQuerySchema(JsonSchema $schema): array
    {
        return [
            ...$this->paginationSchema($schema),
            'sort' => $schema->string()->description('Field to sort by, dot notation allowed (e.g. duration, origin.file).'),
            'dir' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction.'),
            'where' => $schema->array()->items(
                $schema->object([
                    'field' => $schema->string()->required(),
                    'op' => $schema->string()->enum(['=', '!=', '>', '<', '>=', '<=', 'contains', 'starts_with', 'in', 'null', 'not_null']),
                    'value' => $schema->string(),
                ]),
            )->description('Filter clauses, all must match. Example: [{"field":"duration","op":">","value":"20"}].'),
            'fields' => $schema->array()->items($schema->string())->description('Only return these fields (dot notation) to save tokens.'),
            'verbose' => $schema->boolean()->description('Include full origin call chains and other large fields.'),
        ];
    }
}
