<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/** @internal */
final class RowsCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:rows';

    protected $description = 'Paginated, filterable rows of one section (same output as the monocle-rows MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        return $this->outputJson($viewfinder->rows([
            'id' => $this->argument('id'),
            'section' => $this->argument('section'),
            'where' => $this->parseWhere($this->option('where')),
            'fields' => $this->option('field'),
            'kinds' => $this->option('kind'),
            'verbose' => $this->option('verbose-origin'),
            ...$this->optionArgs(['offset', 'limit', 'sort', 'dir']),
        ]));
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('section', InputArgument::REQUIRED, 'Section name (queries, cache, timeline, ...)'),
            new InputArgument('id', InputArgument::OPTIONAL, 'Capture id or "latest"', 'latest'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('where', 'w', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter as field:op:value, e.g. duration:>:20'),
            new InputOption('field', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only output these fields'),
            new InputOption('kind', 'k', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Timeline: only these span kinds'),
            new InputOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort field'),
            new InputOption('dir', null, InputOption::VALUE_REQUIRED, 'asc|desc'),
            new InputOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip this many rows'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per page (default 50)'),
            new InputOption('verbose-origin', null, InputOption::VALUE_NONE, 'Include full origin call chains'),
            ...$this->jsonOptions(),
        ];
    }
}
