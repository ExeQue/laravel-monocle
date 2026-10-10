<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Reader\Presenter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

final class TraceCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:trace';

    protected $description = 'Show every entry in the trace of the given entry (same output as the monocle-trace MCP tool)';

    public function handle(Presenter $presenter): int
    {
        return $this->outputJson($presenter->trace(['id' => $this->argument('id')]));
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('id', InputArgument::OPTIONAL, 'Any entry id in the trace or "latest"', 'latest'),
        ];
    }

    protected function getOptions(): array
    {
        return $this->jsonOptions();
    }
}
