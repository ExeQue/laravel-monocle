<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;

final class FailingCommand extends Command
{
    protected $signature = 'demo:fail';

    protected $description = 'Exits with code 1, for a failed command.';

    public function handle(): int
    {
        $this->error('Nothing to import.');

        return self::FAILURE;
    }
}
