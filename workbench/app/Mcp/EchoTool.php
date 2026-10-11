<?php

declare(strict_types=1);

namespace Workbench\App\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('echo')]
#[Description('Echoes the text back.')]
final class EchoTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['text' => $schema->string()->required()];
    }

    public function handle(Request $request): Response
    {
        return Response::text('echo: '.$request->get('text'));
    }
}
