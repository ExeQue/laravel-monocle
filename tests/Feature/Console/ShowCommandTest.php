<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use ExeQue\Monocle\Console\ShowCommand;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\ConsoleSummaryLens;
use Tests\TestCase;

covers(ShowCommand::class);

describe(ShowCommand::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');
        Route::get('/things', fn () => DB::table('things')->where('id', 1)->first() ?? 'none');
        $this->get('/things')->assertOk();
    });

    it('renders the summary like the dashboard overview', function (): void {
        Artisan::call('monocle:show');
        $output = Artisan::output();

        expect($output)
            ->toContain('GET /things [request]')
            ->toContain('status 200')
            ->toContain('Phase')
            ->toContain('Category')
            ->toContain('Duration')
            ->toContain('queries')
            ->toContain('Sections (--section=...): summary, timeline');
    });

    it('shows the first --limit spans of the timeline and where to find the rest', function (): void {
        Artisan::call('monocle:show', ['--section' => ['timeline'], '--limit' => 2]);
        $output = Artisan::output();

        expect($output)->toContain('Showing 1-2 of')
            ->and($output)->toContain('Next page: php artisan monocle:rows timeline');
    });

    it('keeps only timeline spans matching --search', function (): void {
        Artisan::call('monocle:show', ['--section' => ['timeline'], '--search' => 'phase', '--json' => true]);
        $kinds = array_column(json_decode(Artisan::output(), true)['timeline'], 'kind');

        expect($kinds)->not->toBeEmpty()
            ->and(array_unique($kinds))->toBe(['phase']);
    });

    it('renders the timeline as an indented tree', function (): void {
        Artisan::call('monocle:show', ['--section' => ['timeline']]);
        $output = Artisan::output();

        expect($output)
            ->toContain('Timeline')
            ->toContain('Start')
            ->toContain('Kind')
            ->toContain('Kind')
            ->toMatch('/phase\s+│ handle\s/')
            ->toMatch('/controller\s+│   controller\s/');
    });

    it('renders a row section as a table with only the useful columns', function (): void {
        Artisan::call('monocle:show', ['--section' => ['queries'], '--limit' => 1]);
        $output = Artisan::output();

        expect($output)
            ->toContain('Queries')
            ->toContain('Sql')
            ->toContain('select * from "things"')
            ->toContain('Duration')
            ->toContain(' ms ')
            ->not->toContain('Shape Hash');
    });

    it('still prints the JSON payload with --json', function (): void {
        Artisan::call('monocle:show', ['--json' => true, '--section' => ['summary', 'queries']]);
        $data = json_decode(Artisan::output(), true);

        expect($data['summary']['name'])->toBe('GET /things')
            ->and($data['queries']['rows'][0])->toHaveKey('sql');
    });

    it('fails with the error for unknown captures', function (): void {
        $this->artisan('monocle:show', ['id' => 'missing'])->assertFailed();
    });

    it('names the label, tags, parent and children in the header', function (): void {
        TestCase::$bootConfig = ['monocle.lenses.'.ConsoleSummaryLens::class => true];
        $this->refreshApplication();

        Route::get('/parent', fn (): string => 'ok');
        $this->get('/parent', ['X-Monocle-Label' => 'baseline'])->assertOk();
        $parent = latestCapture()->id;

        $child = new Capture('child-run', CaptureType::Job, microtime(true), parentId: $parent, traceId: $parent);
        $child->name = 'ChildJob';
        $child->status = 'processed';
        $child->endedAt = $child->startedAt + 0.01;
        app(StoreManager::class)->store()->put($child);

        Artisan::call('monocle:show', ['id' => $parent]);
        $output = Artisan::output();

        expect($output)->toContain('Label: baseline')
            ->toContain('Tags: ')
            ->toContain('beta')
            ->toContain('Children: 1 (monocle:trace '.$parent.')')
            ->toContain('cache.used')
            ->not->toContain('cache.unused');

        Artisan::call('monocle:show', ['id' => 'child-run']);

        expect(Artisan::output())->toContain('Parent: '.$parent.' (monocle:show '.$parent.')');
    });

    it('leaves out empty totals, breakdowns and spans of an empty capture', function (): void {
        $capture = new Capture('idle', CaptureType::Command, microtime(true));
        $capture->name = 'idle';
        $capture->status = '0';
        $capture->endedAt = $capture->startedAt;
        app(StoreManager::class)->store()->put($capture);

        Artisan::call('monocle:show', ['id' => 'idle', '--section' => ['summary', 'timeline']]);
        $output = Artisan::output();

        expect($output)->toContain('idle [command]')
            ->toContain('No spans.')
            ->not->toContain('Phase')
            ->not->toContain('Category')
            ->not->toContain('Value');
    });

    it('renders a data section as a key/value table', function (): void {
        Artisan::call('monocle:show', ['--section' => ['request']]);

        expect(Artisan::output())->toContain('Request')
            ->toContain('method')
            ->toContain('GET');
    });
});
