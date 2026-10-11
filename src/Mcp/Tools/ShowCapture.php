<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Tools;

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
#[Name('monocle-show')]
#[Description('Show one capture. The default "summary" section gives the time breakdown per phase/category, totals, warnings (slow queries, N+1, duplicates, slow middleware) and trace links. Add "timeline" for the span tree or any section name (queries, cache, middleware, ...) for its first rows; use monocle-rows for more.')]
final class ShowCapture extends Tool
{
    use RespondsWithJson;

    public function __construct(
        private readonly Viewfinder $viewfinder
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Capture id from monocle-list or the X-Monocle-Id response header. Defaults to "latest".'),
            'sections' => $schema->array()->items($schema->string())->description('Sections to include. Default ["summary"]. "timeline" plus every lens section listed in the server instructions; the response also lists available_sections with descriptions and row counts.'),
            'limit' => $schema->integer()->description('Rows per included section (default 25).'),
            'kinds' => $schema->array()->items($schema->string())->description('For timeline: only spans of these kinds (phase, middleware, controller, user, db.transaction, ...).'),
            'verbose' => $schema->boolean()->description('Include full origin call chains.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->json($this->viewfinder->show($request->all()));
    }
}
