<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Reader\Presenter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class ShowCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:show';

    protected $description = 'Show an entry summary, timeline or sections (same output as the monocle-show MCP tool)';

    public function handle(Presenter $presenter): int
    {
        return $this->outputJson($presenter->show([
            'id' => $this->argument('id'),
            'sections' => $this->option('section') ?: ['summary'],
            'kinds' => $this->option('kind'),
            ...$this->optionArgs(['limit', 'verbose']),
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
            new InputOption('section', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sections to include (summary, timeline, queries, ...)'),
            new InputOption('kind', 'k', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Timeline: only these span kinds'),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per section (default 25)'),
            new InputOption('verbose-origin', null, InputOption::VALUE_NONE, 'Include full origin call chains'),
            ...$this->jsonOptions(),
        ];
    }
}
