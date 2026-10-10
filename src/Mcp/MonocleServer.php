<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp;

use ExeQue\Monocle\LensRegistry;
use ExeQue\Monocle\Mcp\Tools\ClearCaptures;
use ExeQue\Monocle\Mcp\Tools\CompareCaptures;
use ExeQue\Monocle\Mcp\Tools\ListCaptures;
use ExeQue\Monocle\Mcp\Tools\ShowCapture;
use ExeQue\Monocle\Mcp\Tools\ShowQueries;
use ExeQue\Monocle\Mcp\Tools\ShowRows;
use ExeQue\Monocle\Mcp\Tools\ShowTrace;
use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('monocle')]
#[Version('0.1.0')]
final class MonocleServer extends Server
{
    private const string INSTRUCTIONS = <<<'MARKDOWN'
Monocle records what the Laravel application did while handling a request, job or
command: timing phases, every middleware, database queries, cache operations,
spans the developers added, and more. Use it after reproducing a request
(browser, curl, test) to find out where time went and what was executed.

Workflow: `monocle-list` to see recent captures (or read the X-Monocle-Id response
header), `monocle-show id=latest` for the summary with time breakdown and warnings,
then `monocle-rows` / `monocle-queries` to dig into one section with filters and
pagination. Every row carries `origin` (user-land file:line) so you can jump to
the code that triggered it.

Sections are written by lenses. Besides `summary` and `timeline` (the span tree:
phases, middleware, controller, transactions, user spans) this application records:
MARKDOWN;

    protected array $tools = [
        ListCaptures::class,
        ShowCapture::class,
        ShowRows::class,
        ShowQueries::class,
        ShowTrace::class,
        CompareCaptures::class,
        ClearCaptures::class,
    ];

    /**
     * The lens list is part of the instructions so the agent knows every
     * section it can ask for before making a single call.
     */
    protected function boot(): void
    {
        $lines = [];

        foreach (Container::getInstance()->make(LensRegistry::class)->descriptions() as $key => $description) {
            $lines[] = "- `{$key}`: {$description}";
        }

        $this->instructions = self::INSTRUCTIONS."\n".implode("\n", $lines ?: ['- (no lenses enabled)']);
    }
}
