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
#[Name('monocle-trace')]
#[Description('Show every capture in the same trace as the given one: the request/command that started it and all jobs it dispatched, with parent links, depth and start offsets.')]
final class ShowTrace extends Tool
{
    use RespondsWithJson;

    public function __construct(private readonly Viewfinder $viewfinder)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Any capture id in the trace. Defaults to "latest".'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->json($this->viewfinder->trace($request->all()));
    }
}
