<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Tui;

use ExeQue\Monocle\Console\Tui\Browser;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Prompts\Key;

covers(Browser::class);

describe(Browser::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');
        Route::get('/first', fn () => DB::table('things')->count());
        Route::get('/second', fn () => 'ok');
        $this->get('/first')->assertOk();
        $this->get('/second')->assertOk();

        $this->rendered = [];
        $this->browser = new Browser(app(Viewfinder::class), function (string $command, array $parameters, int $width): string {
            $this->rendered[] = [$command, $parameters];

            return "line one\nline two";
        });
        $this->browser->refresh();
    });

    it('lists the captures newest first with the first one selected', function (): void {
        $frame = $this->browser->frame(120, 20);

        expect($frame)->toContain('2 captures')
            ->and($frame)->toContain("\e[7m".' ')
            ->and(strpos($frame, 'GET /second'))->toBeLessThan(strpos($frame, 'GET /first'));
    });

    it('fills exactly the screen height', function (): void {
        expect(explode(PHP_EOL, $this->browser->frame(120, 20)))->toHaveCount(20);
    });

    it('opens the selected capture with a tab per section', function (): void {
        $this->browser->handle('j', 20);
        $this->browser->handle(Key::ENTER, 20);
        $frame = $this->browser->frame(120, 20);

        expect($frame)->toContain('GET /first')
            ->and($frame)->toContain('Overview')
            ->and($frame)->toContain('Queries')
            ->and($frame)->toContain('line one')
            ->and($this->rendered[0][0])->toBe('monocle:show')
            ->and($this->rendered[0][1]['--section'])->toBe(['summary']);
    });

    it('renders a section tab with monocle:rows', function (): void {
        $this->browser->handle('j', 20);
        $this->browser->handle(Key::ENTER, 20);
        $this->browser->frame(120, 20);
        $this->browser->handle('i', 20);
        $this->browser->handle('i', 20);
        $this->browser->frame(120, 20);

        expect(end($this->rendered)[0])->toBe('monocle:rows');
    });

    it('goes back to the list and quits', function (): void {
        $this->browser->handle(Key::ENTER, 20);
        $this->browser->handle(Key::ESCAPE, 20);

        expect($this->browser->frame(120, 20))->toContain('2 captures');

        $this->browser->handle('q', 20);

        expect($this->browser->quitting())->toBeTrue();
    });

    it('cuts lines to the width without counting colour codes', function (): void {
        foreach (explode(PHP_EOL, $this->browser->frame(30, 10)) as $line) {
            expect(mb_strwidth((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line)))->toBeLessThanOrEqual(30);
        }
    });
});
