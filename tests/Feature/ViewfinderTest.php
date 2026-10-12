<?php

declare(strict_types=1);

namespace Tests\Feature;

use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use ExeQue\Monocle\Reader\QueryAggregator;
use ExeQue\Monocle\Reader\SectionReader;
use ExeQue\Monocle\Reader\SummaryBuilder;
use ExeQue\Monocle\Reader\TimelineBuilder;
use ExeQue\Monocle\Storage\Index;
use ExeQue\Monocle\Storage\StoreManager;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

covers(Viewfinder::class, CaptureReader::class, SectionReader::class, QueryAggregator::class, SummaryBuilder::class, TimelineBuilder::class, Index::class);

describe(Viewfinder::class, function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');

        Route::get('/things', function () {
            DB::transaction(fn () => DB::table('things')->insert(['name' => 'a']));
            DB::table('things')->where('id', 1)->first();

            return response('ok', 500);
        });

        $this->get('/things');
    });

    it('reads associative sections as data and unknown sections as empty row sets', function (): void {
        $request = app(Viewfinder::class)->show(['sections' => ['request', 'nothing']]);

        expect($request['request']['data']['method'])->toBe('GET')
            ->and($request['nothing']['total'])->toBe(0)
            ->and(collect($request['available_sections'])->firstWhere('key', 'queries')['rows'])->toBe(4);
    });

    it('serves the timeline through rows with kinds and verbose origins', function (): void {
        $rows = app(Viewfinder::class)->rows(['section' => 'timeline', 'kinds' => ['db.transaction'], 'verbose' => true]);

        expect($rows['section'])->toBe('timeline')
            ->and($rows['total'])->toBe(1)
            ->and($rows['rows'][0]['kind'])->toBe('db.transaction')
            ->and($rows['rows'][0]['meta']['outcome'])->toBe('committed')
            ->and($rows['rows'][0]['origin'])->toBeArray();

        $compact = app(TimelineBuilder::class)->build(latestCapture(), ['db.transaction']);

        expect($compact[0]['origin'])->toBeString();
    });

    it('reports missing captures from every entry point', function (): void {
        $viewfinder = app(Viewfinder::class);

        expect($viewfinder->rows(['id' => 'nope', 'section' => 'queries'])['error'])->toContain('[nope]')
            ->and($viewfinder->queries(['id' => 'nope'])['error'])->toContain('[nope]')
            ->and($viewfinder->trace(['id' => 'nope'])['error'])->toContain('[nope]');
    });

    it('groups queries by table and transaction and flags unknown callers', function (): void {
        $capture = latestCapture();
        $aggregator = app(QueryAggregator::class);

        $byTable = $aggregator->group($capture, 'table', 'count');
        $byTransaction = $aggregator->group($capture, 'transaction', 'bogus');

        $queries = array_values(array_filter($capture->rows('queries'), fn (array $row): bool => $row['type'] !== 'transaction'));

        expect($byTable[0]['key'])->toBe('things')
            ->and($byTable[0]['count'])->toBe(2)
            ->and($byTable[0]['rows'])->toBe(array_column($queries, 'n'))
            ->and(array_column($byTransaction, 'key'))->toContain('(none)');

        $capture->sections['queries'] = [['sql' => 'select 1', 'duration' => 1.0, 'tables' => []]];

        expect($aggregator->group($capture, 'caller')[0]['key'])->toBe('unknown')
            ->and($aggregator->group($capture, 'table')[0]['key'])->toBe('(none)');
    });

    it('summarizes 5xx responses as errors and open spans as warnings', function (): void {
        $summary = app(SummaryBuilder::class)->build(latestCapture());

        expect($summary['errors'])->toContain('Response status 500.')
            ->and($summary['status'])->toBe('500')
            ->and($summary['headlines'])->toContain('duration_ms', 'memory_peak_mb', 'response_bytes', 'monocle_ms', 'monocle_percent')
            ->and($summary['totals']['monocle_percent'])->toBe(round($summary['totals']['monocle_ms'] / $summary['totals']['duration_ms'] * 100, 1))
            ->and($summary['labels']['monocle_ms'])->toBe('monocle')
            ->and($summary['details']['monocle_ms'])->toBe(latestCapture()->health['overhead_by'])->not->toBeEmpty()
            ->and($summary['monocle_by_section'])->toHaveKey('queries')
            // Each section is rounded on its own, so the sums can differ in the last decimal.
            ->and(array_sum($summary['monocle_by_section']))->toEqualWithDelta(array_sum($summary['details']['monocle_ms']), 0.05)
            ->and($summary['groups'])->toMatchArray(['queries' => 'queries'])
            ->and($summary['groups'])->not->toHaveKey('response_bytes')
            ->and($summary['flags'])->toContain(['level' => 'error', 'section' => 'request', 'message' => 'Response status 500.'])
            ->and(array_column($summary['flags'], 'level'))->toBe([
                ...array_fill(0, count($summary['errors']), 'error'),
                ...array_fill(0, count($summary['warnings']), 'warning'),
            ]);

        app(Eyepiece::class)->start(CaptureType::Request);
        app(Eyepiece::class)->span('forgotten')->start();
        $open = app(Eyepiece::class)->finalize();

        expect(app(SummaryBuilder::class)->build($open)['warnings'])->toContain('1 span(s) were never stopped and were closed at finalize.')
            ->and(app(SummaryBuilder::class)->phases($open))->toBe([]);
    });

    it('marks captures with errors in the index and lists children', function (): void {
        $index = Index::summarize(latestCapture());

        expect($index['has_errors'])->toBeTrue()
            ->and($index['counts'])->toHaveKey('queries');

        $failed = new Capture('f1', CaptureType::Job, microtime(true), parentId: latestCapture()->id);
        $failed->status = 'failed';

        expect(Index::summarize($failed)['has_errors'])->toBeTrue();

        $withException = new Capture('f2', CaptureType::Request, microtime(true));
        $withException->sections['exceptions'] = [['class' => 'E']];

        expect(Index::summarize($withException)['has_errors'])->toBeTrue()
            ->and(Index::summarize(new Capture('f3', CaptureType::Request, microtime(true)))['has_errors'])->toBeFalse();

        $lensError = new Capture('f4', CaptureType::Request, microtime(true));
        $lensError->health['errors'] = 2;

        expect(Index::summarize($lensError))->toMatchArray(['has_errors' => true, 'errors' => 2]);

        app(StoreManager::class)->store()->put($failed);

        expect(app(CaptureReader::class)->children(latestCapture()->parentId ?? $failed->parentId))->toHaveCount(1)
            ->and(app(CaptureReader::class)->list(Filter::fromArray(['has_errors' => true]))['total'])->toBe(2);
    });

    it('lists queued jobs of a trace that no worker has run yet', function (): void {
        $parent = new Capture('trace-parent', CaptureType::Request, microtime(true), traceId: 'trace-1');
        $parent->sections['jobs'] = [
            ['job' => 'App\\Jobs\\Ran', 'run' => 'job-ran'],
            ['job' => 'App\\Jobs\\Sync', 'run' => null],
            ['job' => 'App\\Jobs\\Waiting', 'run' => 'job-waiting'],
        ];

        $worker = new Capture('trace-worker', CaptureType::Job, microtime(true), parentId: 'trace-parent', traceId: 'trace-1');
        $worker->sections['job'] = ['job_id' => 'job-ran'];

        app(StoreManager::class)->store()->put($parent);
        app(StoreManager::class)->store()->put($worker);

        $trace = app(Viewfinder::class)->trace(['id' => 'trace-parent']);

        expect($trace['trace_id'])->toBe('trace-1')
            ->and($trace['captures'])->toHaveCount(2)
            ->and($trace['pending'])->toBe([['parent_id' => 'trace-parent', 'job' => 'App\\Jobs\\Waiting', 'job_id' => 'job-waiting']]);
    });

    it('traces a page through the calls it made to the commands they started', function (): void {
        config(['monocle.trace.browser.enabled' => true]);
        Route::get('/page', fn () => response('<body>page</body>'));
        Route::get('/call', fn () => 'called');
        Artisan::command('monocle-test:child', fn (): int => 0);

        $this->get('/page');
        $page = latestCapture();

        $this->get('/call', ['X-Monocle-Parent' => $page->id]);
        $call = latestCapture();

        putenv('MONOCLE_PARENT_ID='.$call->id);
        putenv('MONOCLE_TRACE_ID='.$call->traceId);

        try {
            Artisan::call('monocle-test:child');
        } finally {
            putenv('MONOCLE_PARENT_ID');
            putenv('MONOCLE_TRACE_ID');
        }

        $trace = app(Viewfinder::class)->trace(['id' => $page->id]);

        expect(array_column($trace['captures'], 'name'))->toBe(['GET /page', 'GET /call', 'artisan monocle-test:child'])
            ->and(array_column($trace['captures'], 'depth'))->toBe([0, 1, 2])
            ->and(array_column($trace['captures'], 'linked_via'))->toBe([null, 'browser', 'process'])
            ->and(app(CaptureReader::class)->indexRow($call->id)['trace_id'])->toBe($page->id)
            ->and(app(CaptureReader::class)->indexRow('missing'))->toBeNull();
    });

    it('refuses to compare without a first capture', function (): void {
        expect(app(Viewfinder::class)->compare(['a' => ''])['error'])->toBe('Capture [] not found by id or label.');
    });
});
