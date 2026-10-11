<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Workbench\App\Models\User;

final class GreetCommand extends Command
{
    protected $signature = 'demo:greet {name=World} {--shout}';

    protected $description = 'Greets someone and counts the users.';

    public function handle(): int
    {
        $greeting = 'Hello '.$this->argument('name').', there are '.User::query()->count().' users.';

        $this->info($this->option('shout') ? strtoupper($greeting) : $greeting);

        return self::SUCCESS;
    }
}
