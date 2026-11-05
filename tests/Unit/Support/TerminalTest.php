<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use ExeQue\Monocle\Support\Terminal;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\StreamOutput;

covers(Terminal::class);

describe(Terminal::class, function (): void {
    it('is not interactive for a buffer or a stream that is not a terminal', function (): void {
        $terminal = new Terminal();

        expect($terminal->interactive(new BufferedOutput()))->toBeFalse()
            ->and($terminal->interactive(new StreamOutput(fopen('php://memory', 'w+'))))->toBeFalse();
    });

    it('opens links unless the terminal is one Symfony leaves them out for', function (): void {
        $emulator = getenv('TERMINAL_EMULATOR');
        $konsole = getenv('KONSOLE_VERSION');
        $idea = $_SERVER['IDEA_INITIAL_DIRECTORY'] ?? null;
        unset($_SERVER['IDEA_INITIAL_DIRECTORY']);

        try {
            putenv('TERMINAL_EMULATOR');
            putenv('KONSOLE_VERSION');
            $opens = new Terminal()->opensLinks();

            putenv('TERMINAL_EMULATOR=JetBrains-JediTerm');
            $jetbrains = new Terminal()->opensLinks();

            putenv('TERMINAL_EMULATOR');
            putenv('KONSOLE_VERSION=201100');
            $oldKonsole = new Terminal()->opensLinks();
        } finally {
            putenv($emulator === false ? 'TERMINAL_EMULATOR' : 'TERMINAL_EMULATOR='.$emulator);
            putenv($konsole === false ? 'KONSOLE_VERSION' : 'KONSOLE_VERSION='.$konsole);

            if ($idea !== null) {
                $_SERVER['IDEA_INITIAL_DIRECTORY'] = $idea;
            }
        }

        expect($opens)->toBeTrue()
            ->and($jetbrains)->toBeFalse()
            ->and($oldKonsole)->toBeFalse();
    });

    it('answers what it was told instead of asking', function (): void {
        $terminal = new Terminal(interactive: true, opensLinks: false);

        expect($terminal->interactive(new BufferedOutput()))->toBeTrue()
            ->and($terminal->opensLinks())->toBeFalse();
    });
});
