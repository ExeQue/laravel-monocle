<?php

declare(strict_types=1);

namespace Workbench\App\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('demo-server')]
final class DemoServer extends Server
{
    protected array $tools = [EchoTool::class];
}
