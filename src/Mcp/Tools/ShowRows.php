<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Tools;

use ExeQue\Monocle\Mcp\Tools\Concerns\DescribesPagination;
use ExeQue\Monocle\Mcp\Tools\Concerns\RespondsWithJson;
use ExeQue\Monocle\Reader\Presenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('monocle-rows')]
#[Description('Paginated, filterable rows of one section of an entry (queries, cache, timeline, or any section listed by monocle-show). Supports where-clauses, sorting and field projection so you only fetch what you need.')]
final class ShowRows extends Tool
{
    use DescribesPagination;
    use RespondsWithJson;

    public function __construct(private readonly Presenter $presenter)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id. Defaults to "latest".'),
            'section' => $schema->string()->required()->description('Section name, e.g. queries, cache, timeline.'),
            'kinds' => $schema->array()->items($schema->string())->description('For timeline: only spans of these kinds.'),
            ...$this->rowQuerySchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->json($this->presenter->rows($request->all()));
    }
}
