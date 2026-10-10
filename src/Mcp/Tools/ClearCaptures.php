<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Tools;

use ExeQue\Monocle\Mcp\Tools\Concerns\RespondsWithJson;
use ExeQue\Monocle\Viewfinder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Name('monocle-clear')]
#[Description('Delete all recorded captures. Useful before reproducing a scenario so monocle-list only shows what you trigger next.')]
final class ClearCaptures extends Tool
{
    use RespondsWithJson;

    public function __construct(private readonly Viewfinder $viewfinder)
    {
    }

    public function handle(Request $request): Response
    {
        return $this->json($this->viewfinder->clear());
    }
}
