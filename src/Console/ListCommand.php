<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

final class ListCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:list';

    protected $description = 'List recorded captures newest first (same output as the monocle-list MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        return $this->outputJson($viewfinder->list([
            'has' => $this->option('has'),
            'exclude' => $this->option('exclude'),
            ...$this->optionArgs(['type', 'uri', 'status', 'min-duration-ms', 'since', 'label', 'has-errors', 'offset', 'limit']),
        ]));
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('type', null, InputOption::VALUE_REQUIRED, 'request|job|command|mcp'),
            new InputOption('uri', null, InputOption::VALUE_REQUIRED, 'Substring of the capture name'),
            new InputOption('status', null, InputOption::VALUE_REQUIRED, 'Exact HTTP status'),
            new InputOption('min-duration-ms', null, InputOption::VALUE_REQUIRED, 'Only captures at least this slow'),
            new InputOption('since', null, InputOption::VALUE_REQUIRED, 'Unix timestamp, ISO date or relative (15m, 2h)'),
            new InputOption('label', null, InputOption::VALUE_REQUIRED, 'Only captures with this label'),
            new InputOption('has-errors', null, InputOption::VALUE_NONE, 'Only captures with errors'),
            new InputOption('has', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only captures where this section recorded rows (repeatable)'),
            new InputOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Leave out captures whose name contains this (repeatable)'),
            new InputOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip this many rows'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per page (default 20)'),
            ...$this->jsonOptions(),
        ];
    }
}
