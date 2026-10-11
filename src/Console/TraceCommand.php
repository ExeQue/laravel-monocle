<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

/** @internal */
final class TraceCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:trace';

    protected $description = 'Show every capture in the trace of the given capture (same output as the monocle-trace MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        return $this->outputJson($viewfinder->trace(['id' => $this->argument('id')]));
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('id', InputArgument::OPTIONAL, 'Any capture id in the trace or "latest"', 'latest'),
        ];
    }

    protected function getOptions(): array
    {
        return $this->jsonOptions();
    }
}
