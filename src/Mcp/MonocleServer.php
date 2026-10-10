<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Mcp;

use ExeQue\Monocle\Mcp\Tools\ClearEntries;
use ExeQue\Monocle\Mcp\Tools\ListEntries;
use ExeQue\Monocle\Mcp\Tools\ShowEntry;
use ExeQue\Monocle\Mcp\Tools\ShowQueries;
use ExeQue\Monocle\Mcp\Tools\ShowRows;
use ExeQue\Monocle\Mcp\Tools\ShowTrace;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Monocle')]
#[Version('0.1.0')]
#[Instructions(<<<'MARKDOWN'
Monocle records what the Laravel application did while handling a request, job or
command: timing phases, every middleware, database queries, cache operations,
spans the developers added, and more. Use it after reproducing a request
(browser, curl, test) to find out where time went and what was executed.

Workflow: `monocle-list` to see recent entries (or read the X-Monocle-Id response
header), `monocle-show id=latest` for the summary with time breakdown and warnings,
then `monocle-rows` / `monocle-queries` to dig into one section with filters and
pagination. Every row carries `origin` (user-land file:line) so you can jump to
the code that triggered it.
MARKDOWN)]
final class MonocleServer extends Server
{
    protected array $tools = [
        ListEntries::class,
        ShowEntry::class,
        ShowRows::class,
        ShowQueries::class,
        ShowTrace::class,
        ClearEntries::class,
    ];
}
