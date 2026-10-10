<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mcp;

use Generator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('stream')]
final class StreamTool extends Tool
{
    public function handle(Request $request): Generator
    {
        yield Response::notification('processing/progress', ['step' => 1]);
        yield Response::text('done');
    }
}
