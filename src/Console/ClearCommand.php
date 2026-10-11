<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Concerns\OutputsJson;
use ExeQue\Monocle\Storage\StoreManager;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

use Symfony\Component\Console\Input\InputOption;

/** @internal */
final class ClearCommand extends Command
{
    use OutputsJson;

    protected $name = 'monocle:clear';

    protected $description = 'Delete all recorded captures';

    public function handle(Viewfinder $viewfinder, StoreManager $stores): int
    {
        $count = count($stores->store()->index());

        if (! $this->confirmed($count)) {
            note('Nothing was cleared.');

            return self::SUCCESS;
        }

        return $this->respond($viewfinder->clear(), function () use ($count): void {
            info('Cleared '.$count.' '.($count === 1 ? 'capture' : 'captures').'.');
        });
    }

    protected function getOptions(): array
    {
        return [
            ...$this->jsonOptions(),
            new InputOption('force', 'f', InputOption::VALUE_NONE, 'Clear without asking for confirmation'),
        ];
    }

    private function confirmed(int $count): bool
    {
        if ($this->wantsJson()) {
            return true;
        }

        if ($count === 0) {
            return true;
        }

        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return true;
        }

        return confirm('Delete all '.$count.' recorded '.($count === 1 ? 'capture' : 'captures').'?', default: true);
    }
}
