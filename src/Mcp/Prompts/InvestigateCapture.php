<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp\Prompts;

use ExeQue\Monocle\Viewfinder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * The dashboard's "copy prompt" as a prompt the user picks in the agent
 * (a slash command in Claude Code), with what Monocle flagged.
 *
 * @internal
 */
#[Name('investigate')]
#[Description('Look into a capture: what is slow or wrong, where to fix it, and compare after the fix.')]
final class InvestigateCapture extends Prompt
{
    public function __construct(
        private readonly Viewfinder $viewfinder
    ) {
    }

    public function arguments(): array
    {
        return [
            new Argument('id', 'Capture id, from the dashboard or the X-Monocle-Id response header. Defaults to the latest capture.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $result = $this->viewfinder->show(['id' => $request->get('id') ?: 'latest']);

        if (isset($result['error'])) {
            return Response::error((string) $result['error']);
        }

        $summary = $result['summary'];
        $id = $summary['id'];
        $status = $summary['status'] === null ? '' : ", status {$summary['status']}";

        $lines = [
            sprintf('Use the Monocle MCP server to look into capture %s: %s (%s%s, %s ms).', $id, $summary['name'], $summary['type'], $status, round((float) $summary['totals']['duration_ms'], 1)),
            "Start with monocle-show id={$id}, then dig into the sections that matter with monocle-rows and monocle-queries.",
        ];

        if ($summary['flags'] !== []) {
            $lines[] = '';
            $lines[] = 'Monocle flagged:';

            foreach ($summary['flags'] as $flag) {
                $lines[] = "- {$flag['level']} in {$flag['section']}: {$flag['message']}";
            }
        }

        $lines[] = '';
        $lines[] = "Explain what is slow or wrong, point at the file:line to change (every row has an origin), make the change, reproduce the request and compare the new capture with monocle-compare a={$id}.";

        return Response::text(implode("\n", $lines));
    }
}
