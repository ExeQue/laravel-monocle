<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use ExeQue\Monocle\Mcp\MonocleServer;
use ExeQue\Monocle\Mcp\Tools\ClearCaptures;
use ExeQue\Monocle\Mcp\Tools\ListCaptures;
use ExeQue\Monocle\Mcp\Tools\ShowCapture;
use ExeQue\Monocle\Mcp\Tools\ShowQueries;
use ExeQue\Monocle\Mcp\Tools\ShowRows;
use ExeQue\Monocle\Mcp\Tools\ShowTrace;
use ExeQue\Monocle\Reader\SummaryBuilder;
use ExeQue\Monocle\Reader\TimelineBuilder;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Transport\FakeTransporter;

covers(MonocleServer::class, ListCaptures::class, ShowCapture::class, ShowRows::class, ShowQueries::class, ShowTrace::class, ClearCaptures::class, \ExeQue\Monocle\Mcp\Tools\ListLenses::class, Viewfinder::class, SummaryBuilder::class, TimelineBuilder::class);

/**
 * Run the tool through the MCP server (asserting success) and return the same
 * payload from the Viewfinder the tool delegates to.
 */
function toolJson(string $tool, array $args, string $method): array
{
    MonocleServer::tool($tool, $args)->assertOk()->assertHasNoErrors();

    return app(Viewfinder::class)->{$method}($args);
}

describe('MCP tools', function (): void {
    beforeEach(function (): void {
        DB::statement('create table things (id integer primary key, name text)');

        Route::name('things.index')->get('/things', function () {
            DB::table('things')->insert(['name' => 'a']);

            foreach ([1, 2, 3] as $id) {
                DB::table('things')->where('id', $id)->first();
            }

            return 'ok';
        });

        $this->get('/things')->assertOk();
    });

    it('lists captures with filters and pagination', function (): void {
        $result = toolJson(ListCaptures::class, ['uri' => 'things', 'limit' => 5], 'list');

        expect($result['total'])->toBe(1)
            ->and($result['rows'][0]['name'])->toBe('GET /things')
            ->and($result['rows'][0]['counts']['queries'])->toBe(4)
            ->and(toolJson(ListCaptures::class, ['type' => 'job'], 'list')['total'])->toBe(0)
            ->and(toolJson(ListCaptures::class, ['exclude' => ['/THINGS']], 'list')['total'])->toBe(0)
            ->and(toolJson(ListCaptures::class, ['exclude' => ['/other']], 'list')['total'])->toBe(1)
            ->and(toolJson(ListCaptures::class, ['exclude' => ['things.index']], 'list')['total'])->toBe(0)
            ->and($result['rows'][0]['route'])->toBe('things.index');
    });

    it('shows the summary with breakdown, warnings and section descriptions', function (): void {
        $result = toolJson(ShowCapture::class, ['id' => 'latest'], 'show');
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
        $result = toolJson(ShowCapture::class, ['sections' => ['timeline'], 'kinds' => ['phase']], 'show');

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

    it('shows the trace of a capture', function (): void {
        $result = toolJson(ShowTrace::class, [], 'trace');

        expect($result['captures'])->toHaveCount(1)
            ->and($result['captures'][0]['depth'])->toBe(0)
            ->and($result['captures'][0]['offset_ms'])->toBe(0.0);
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

    it('reports missing captures as errors and clears the store', function (): void {
        MonocleServer::tool(ShowCapture::class, ['id' => 'nope'])->assertHasErrors();

        toolJson(ClearCaptures::class, [], 'clear');

        MonocleServer::tool(ShowCapture::class, [])->assertHasErrors(['No captures recorded yet. Make a request to the application first.']);
    });

    it('lists the enabled lenses with their section keys', function (): void {
        MonocleServer::tool(\ExeQue\Monocle\Mcp\Tools\ListLenses::class)
            ->assertOk()
            ->assertSee(['section', 'queries', 'cache', 'Every executed query']);
    });
});
