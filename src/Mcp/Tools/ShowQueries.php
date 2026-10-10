<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Tools;

use ExeQue\Monocle\Mcp\Tools\Concerns\DescribesPagination;
use ExeQue\Monocle\Mcp\Tools\Concerns\RespondsWithJson;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('monocle-queries')]
#[Description('Aggregate the database queries of a capture by shape (normalized SQL), caller (origin file:line), table or transaction: count, total/avg/max ms and an example. Finds N+1 and duplicate work faster than reading rows.')]
final class ShowQueries extends Tool
{
    use DescribesPagination;
    use RespondsWithJson;

    public function __construct(private readonly Viewfinder $viewfinder)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Capture id. Defaults to "latest".'),
            'group_by' => $schema->string()->enum(['shape', 'caller', 'table', 'transaction'])->description('Grouping key (default shape).'),
            'sort' => $schema->string()->enum(['total_ms', 'count', 'max_ms', 'avg_ms'])->description('Sort groups descending by this (default total_ms).'),
            ...$this->paginationSchema($schema, 25),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->json($this->viewfinder->queries($request->all()));
    }
}
