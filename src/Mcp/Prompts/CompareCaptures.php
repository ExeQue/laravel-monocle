<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/** @internal */
#[Name('compare')]
#[Description('Compare a capture from before a change with one from after it: did it help, and did anything get worse?')]
final class CompareCaptures extends Prompt
{
    public function arguments(): array
    {
        return [
            new Argument('before', 'Capture id or label (X-Monocle-Label) from before the change.', required: true),
            new Argument('after', 'Capture id or label from after the change. Defaults to the latest capture.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $before = (string) $request->get('before');
        $after = $request->get('after') ?: 'latest';

        return Response::text(implode("\n", [
            "Use the Monocle MCP server to compare capture {$before} (before a change) with {$after} (after it): run monocle-compare a={$before} b={$after}.",
            'Say whether the change helped: duration, time per phase, queries and other totals. Call out anything that got worse: new queries, errors or warnings only the new capture has. Look into a regression with monocle-show and monocle-rows on the new capture, and point at the file:line behind it.',
        ]));
    }
}
