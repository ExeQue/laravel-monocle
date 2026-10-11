<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;

/** @internal */
final class ClearCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:clear';

    protected $description = 'Delete all recorded captures';

    public function handle(Viewfinder $viewfinder): int
    {
        return $this->outputJson($viewfinder->clear());
    }

    protected function getOptions(): array
    {
        return $this->jsonOptions();
    }
}
