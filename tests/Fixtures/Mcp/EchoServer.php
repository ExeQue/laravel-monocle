<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('echo-server')]
final class EchoServer extends Server
{
    protected array $tools = [EchoTool::class, StreamTool::class];
}
