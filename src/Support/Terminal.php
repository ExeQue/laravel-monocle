<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * What the terminal a command writes to can do. Both answers can be given up
 * front, which is how the tests stand in for a real terminal.
 *
 * @internal
 */
final class Terminal
{
    public function __construct(
        private readonly ?bool $interactive = null,
        private readonly ?bool $opensLinks = null,
    ) {
    }

    /**
     * Whether the output reaches a person at a terminal rather than a file, a pipe or a log.
     */
    public function interactive(OutputInterface $output): bool
    {
        return $this->interactive ?? ($output instanceof StreamOutput && stream_isatty($output->getStream()));
    }

    /**
     * The terminals Symfony's formatter writes links for; it drops them silently elsewhere.
     */
    public function opensLinks(): bool
    {
        return $this->opensLinks ?? (getenv('TERMINAL_EMULATOR') !== 'JetBrains-JediTerm'
            && (! getenv('KONSOLE_VERSION') || (int) getenv('KONSOLE_VERSION') > 201100)
            && ! isset($_SERVER['IDEA_INITIAL_DIRECTORY']));
    }
}
