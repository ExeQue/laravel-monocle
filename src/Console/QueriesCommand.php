<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Reader\Presenter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class QueriesCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:queries';

    protected $description = 'Aggregate the queries of an entry by shape, caller, table or transaction (same output as the monocle-queries MCP tool)';

    public function handle(Presenter $presenter): int
    {
        return $this->outputJson($presenter->queries([
            'id' => $this->argument('id'),
            ...$this->optionArgs(['group-by', 'sort', 'offset', 'limit']),
        ]));
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('id', InputArgument::OPTIONAL, 'Entry id or "latest"', 'latest'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            new InputOption('group-by', 'g', InputOption::VALUE_REQUIRED, 'shape|caller|table|transaction', 'shape'),
            new InputOption('sort', null, InputOption::VALUE_REQUIRED, 'total_ms|count|max_ms|avg_ms', 'total_ms'),
            new InputOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip this many groups'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Groups per page (default 25)'),
            ...$this->jsonOptions(),
        ];
    }
}
