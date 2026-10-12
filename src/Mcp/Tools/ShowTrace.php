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

/** @internal */
#[IsReadOnly]
#[Name('monocle-trace')]
#[Description('Show every capture in the same trace as the given one: the request/command that started it and every capture started from it (queued jobs, requests made by a page with the browser trace script, commands run through the Process facade), with parent links, how each was linked (linked_via: queue, browser, navigation, process), depth and start offsets. pending lists queued jobs no worker has run yet ({parent_id, job, job_id}).')]
final class ShowTrace extends Tool
{
    use RespondsWithJson;

    public function __construct(
        private readonly Viewfinder $viewfinder
    ) {
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
