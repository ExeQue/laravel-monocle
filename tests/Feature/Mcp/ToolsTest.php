<?php

declare(strict_types=1);

use ExeQue\Monocle\Mcp\MonocleServer;
use ExeQue\Monocle\Mcp\Tools\ClearEntries;
use ExeQue\Monocle\Mcp\Tools\ListEntries;
use ExeQue\Monocle\Mcp\Tools\ShowEntry;
use ExeQue\Monocle\Mcp\Tools\ShowQueries;
use ExeQue\Monocle\Mcp\Tools\ShowRows;
use ExeQue\Monocle\Mcp\Tools\ShowTrace;
use ExeQue\Monocle\Reader\Presenter;
use ExeQue\Monocle\Reader\SummaryBuilder;
use ExeQue\Monocle\Reader\TimelineBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Transport\FakeTransporter;

covers(MonocleServer::class, ListEntries::class, ShowEntry::class, ShowRows::class, ShowQueries::class, ShowTrace::class, ClearEntries::class, Presenter::class, SummaryBuilder::class, TimelineBuilder::class);

/**
 * Run the tool through the MCP server (asserting success) and return the same
 * payload from the Presenter the tool delegates to.
 */
function toolJson(string $tool, array $args, string $method): array
{
    MonocleServer::tool($tool, $args)->assertOk()->assertHasNoErrors();

    return app(Presenter::class)->{$method}($args);
}

describe('MCP tools', function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');

        Route::get('/things', function () {
            DB::table('things')->insert(['name' => 'a']);

            foreach ([1, 2, 3] as $id) {
                DB::table('things')->where('id', $id)->first();
            }

            return 'ok';
        });

        $this->get('/things')->assertOk();
    });

    it('lists entries with filters and pagination', function (): void {
        $result = toolJson(ListEntries::class, ['uri' => 'things', 'limit' => 5], 'list');

        expect($result['total'])->toBe(1)
            ->and($result['rows'][0]['name'])->toBe('GET /things')
            ->and($result['rows'][0]['counts']['queries'])->toBe(4)
            ->and(toolJson(ListEntries::class, ['type' => 'job'], 'list')['total'])->toBe(0);
    });

    it('shows the summary with breakdown, warnings and section descriptions', function (): void {
        $result = toolJson(ShowEntry::class, ['id' => 'latest'], 'show');
        $summary = $result['summary'];
        $phaseNames = array_column($summary['breakdown']['phases'], 'name');
        $sections = array_column($result['available_sections'], 'description', 'key');

        expect($summary['name'])->toBe('GET /things')
            ->and($phaseNames)->toContain('routing', 'controller', 'other')
            ->and(array_column($summary['breakdown']['categories'], 'name'))->toContain('db')
            ->and($summary['totals']['queries'])->toBe(4)
            ->and(implode(' ', $summary['warnings']))->toContain('N+1')
            ->and($sections)->toHaveKey('queries')
            ->and($sections['queries'])->toContain('Every executed query');
    });

    it('shows the timeline filtered by kind', function (): void {
        $result = toolJson(ShowEntry::class, ['sections' => ['timeline'], 'kinds' => ['phase']], 'show');

        expect(array_unique(array_column($result['timeline'], 'kind')))->toBe(['phase'])
            ->and($result['timeline'][0])->toHaveKeys(['depth', 'start', 'duration']);
    });

    it('returns filtered, projected rows of a section', function (): void {
        $result = toolJson(ShowRows::class, [
            'section' => 'queries',
            'where' => [['field' => 'type', 'op' => '=', 'value' => 'select']],
            'fields' => ['n', 'sql', 'origin'],
            'limit' => 2,
        ], 'rows');

        expect($result['total'])->toBe(3)
            ->and($result['returned'])->toBe(2)
            ->and($result['next_offset'])->toBe(2)
            ->and($result['rows'][0])->toHaveKeys(['n', 'sql', 'origin'])
            ->and($result['rows'][0])->not->toHaveKey('bindings')
            ->and($result['rows'][0]['origin'])->toBeString();
    });

    it('aggregates queries by shape and caller', function (): void {
        $byShape = toolJson(ShowQueries::class, ['group_by' => 'shape', 'sort' => 'count'], 'queries');
        $byCaller = toolJson(ShowQueries::class, ['group_by' => 'caller'], 'queries');

        expect($byShape['rows'][0]['count'])->toBe(3)
            ->and($byShape['rows'][0]['example'])->toContain('select')
            ->and($byCaller['rows'][0]['key'])->toContain('ToolsTest.php');
    });

    it('shows the trace of an entry', function (): void {
        $result = toolJson(ShowTrace::class, [], 'trace');

        expect($result['entries'])->toHaveCount(1)
            ->and($result['entries'][0]['depth'])->toBe(0)
            ->and($result['entries'][0]['offset_ms'])->toBe(0.0);
    });

    it('lists every lens section with its description in the server instructions', function (): void {
        $server = app(MonocleServer::class, ['transport' => new FakeTransporter()]);
        $server->start();

        $instructions = $server->createContext()->instructions;

        expect($instructions)->toContain('`queries`: Every executed query')
            ->and($instructions)->toContain('`cache`:')
            ->and($instructions)->toContain('`middleware`:')
            ->and($instructions)->toContain('monocle-list');
    });

    it('reports missing entries as errors and clears the store', function (): void {
        MonocleServer::tool(ShowEntry::class, ['id' => 'nope'])->assertHasErrors();

        toolJson(ClearEntries::class, [], 'clear');

        MonocleServer::tool(ShowEntry::class, [])->assertHasErrors(['No entries recorded yet. Make a request to the application first.']);
    });
});
