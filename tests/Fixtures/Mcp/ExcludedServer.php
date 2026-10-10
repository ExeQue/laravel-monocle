<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('laravel-boost')]
final class ExcludedServer extends Server
{
    protected array $tools = [EchoTool::class];
}
