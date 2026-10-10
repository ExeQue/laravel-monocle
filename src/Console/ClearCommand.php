<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Reader\Presenter;
use Illuminate\Console\Command;

final class ClearCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:clear';

    protected $description = 'Delete all recorded entries';

    public function handle(Presenter $presenter): int
    {
        return $this->outputJson($presenter->clear());
    }

    protected function getOptions(): array
    {
        return $this->jsonOptions();
    }
}
