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
#[Name('monocle-list')]
#[Description('List recently recorded entries (requests, jobs, commands) newest first with duration, status and per-section counts. Start here, then call monocle-show with an id.')]
final class ListEntries extends Tool
{
    use DescribesPagination;
    use RespondsWithJson;

    public function __construct(private readonly Presenter $presenter)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['request', 'job', 'command', 'mcp'])->description('Only entries of this type.'),
            'uri' => $schema->string()->description('Substring of the entry name, e.g. "/api/orders" or "POST".'),
            'status' => $schema->integer()->description('Exact HTTP status (requests) to match.'),
            'min_duration_ms' => $schema->number()->description('Only entries at least this slow.'),
            'since' => $schema->string()->description('Only entries started after this: unix timestamp, ISO date or relative like "15m", "2h".'),
            'label' => $schema->string()->description('Only entries carrying this label (set via the X-Monocle-Label request header or Monocle::label()).'),
            'has_errors' => $schema->boolean()->description('Only entries with 5xx status, failures or recorded exceptions.'),
            ...$this->paginationSchema($schema, 20),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->json($this->presenter->list($request->all()));
    }
}
