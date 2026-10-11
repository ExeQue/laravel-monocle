<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console;

use ExeQue\Monocle\Console\Tui\Browser;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

use function Laravel\Prompts\error;

use Laravel\Prompts\Terminal;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A full-screen terminal browser for captures: the list updates live, and
 * a capture opens with a tab per section, rendered like the other commands.
 *
 * @internal
 */
final class TuiCommand extends Command
{
    protected $name = 'monocle:tui';

    protected $description = 'Browse captures in a full-screen terminal UI';

    public function handle(Viewfinder $viewfinder): int
    {
        if (! $this->input->isInteractive()) {
            error('monocle:tui needs an interactive terminal. Use monocle:list and monocle:show instead.');

            return self::FAILURE;
        }

        if (! stream_isatty(STDIN)) {
            error('monocle:tui needs an interactive terminal. Use monocle:list and monocle:show instead.');

            return self::FAILURE;
        }

        $browser = new Browser($viewfinder, $this->renderCommand(...));
        $terminal = new Terminal();

        $terminal->setTty('-icanon -echo -isig');
        fwrite(STDOUT, "\e[?1049h\e[?25l");

        try {
            $this->loop($browser, $terminal);
        } finally {
            fwrite(STDOUT, "\e[?25h\e[?1049l");
            $terminal->restoreTty();
        }

        return self::SUCCESS;
    }

    private function loop(Browser $browser, Terminal $terminal): void
    {
        $browser->refresh();
        $previous = '';
        $refreshed = microtime(true);

        while (! $browser->quitting()) {
            $terminal->initDimensions();
            $frame = $browser->frame($terminal->cols(), $terminal->lines());

            if ($frame !== $previous) {
                fwrite(STDOUT, "\e[H\e[2J".$frame);
                $previous = $frame;
            }

            $read = [STDIN];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, 250_000) > 0) {
                $browser->handle((string) fread(STDIN, 1024), $terminal->lines());

                continue;
            }

            if (microtime(true) - $refreshed >= 1) {
                $browser->refresh();
                $refreshed = microtime(true);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function renderCommand(string $command, array $parameters, int $width): string
    {
        $output = new BufferedOutput(decorated: true);

        Artisan::call($command, $parameters, $output);

        return $output->fetch();
    }
}
