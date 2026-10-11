<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

/** @internal */
final class CompareCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:compare';

    protected $description = 'Compare two captures by id or label (same output as the monocle-compare MCP tool)';

    public function handle(Viewfinder $viewfinder): int
    {
        return $this->outputJson($viewfinder->compare([
            'a' => $this->argument('a'),
            'b' => $this->argument('b'),
        ]));
    }

    protected function getArguments(): array
    {
        return [
            new InputArgument('a', InputArgument::REQUIRED, 'Baseline capture id or label'),
            new InputArgument('b', InputArgument::OPTIONAL, 'Capture id or label to compare against', 'latest'),
        ];
    }

    protected function getOptions(): array
    {
        return $this->jsonOptions();
    }
}
