<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Mcp;

use Illuminate\View\View;

final class ErrorResponseController
{
    public function __invoke(): View
    {
        return view('fetch', [
            'title' => 'MCP error',
            'description' => 'Calls a tool the demo MCP server does not have; the server answers with an error.',
            'method' => 'POST',
            'url' => '/api/mcp/demo',
            'headers' => ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'],
            'body' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'weather', 'arguments' => ['city' => 'Aarhus']],
            ],
        ]);
    }
}
