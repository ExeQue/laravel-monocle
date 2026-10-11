<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Mcp;

use Illuminate\View\View;

final class ToolCallController
{
    public function __invoke(): View
    {
        return view('fetch', [
            'title' => 'MCP tool call',
            'description' => 'Calls the echo tool on the demo MCP server. The MCP request is a capture of its own.',
            'method' => 'POST',
            'url' => '/api/mcp/demo',
            'headers' => ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'],
            'body' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'echo', 'arguments' => ['text' => 'hello monocle']],
            ],
        ]);
    }
}
