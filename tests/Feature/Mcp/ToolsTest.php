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

covers(MonocleServer::class, \ExeQue\Monocle\Reader\AgentView::class, ListCaptures::class, ShowCapture::class, ShowRows::class, ShowQueries::class, ShowTrace::class, ClearCaptures::class, \ExeQue\Monocle\Mcp\Tools\ListLenses::class, Viewfinder::class, SummaryBuilder::class, TimelineBuilder::class);

/**
 * Run the tool through the MCP server (asserting success) and return the JSON it answered with.
 */
function toolJson(string $tool, array $args): array
{
    $response = MonocleServer::tool($tool, $args)->assertOk()->assertHasNoErrors();
    $result = (fn (): array => $this->response->toArray())->call($response);

    return json_decode($result['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
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
        $result = toolJson(ListCaptures::class, ['uri' => 'things', 'limit' => 5]);

        expect($result['total'])->toBe(1)
            ->and($result['rows'][0]['name'])->toBe('GET /things')
            ->and($result['rows'][0]['counts']['queries'])->toBe(4)
            ->and(toolJson(ListCaptures::class, ['type' => 'job'])['total'])->toBe(0)
            ->and(toolJson(ListCaptures::class, ['exclude' => ['/THINGS']])['total'])->toBe(0)
            ->and(toolJson(ListCaptures::class, ['exclude' => ['/other']])['total'])->toBe(1)
            ->and(toolJson(ListCaptures::class, ['exclude' => ['things.index']])['total'])->toBe(0)
            ->and($result['rows'][0]['route'])->toBe('things.index')
            ->and($result['rows'][0])->not->toHaveKeys(['label', 'trace_id', 'job_id']);
    });

    it('describes only the lenses asked for', function (): void {
        $lenses = toolJson(\ExeQue\Monocle\Mcp\Tools\ListLenses::class, ['sections' => ['queries']])['lenses'];

        expect(array_column($lenses, 'section'))->toBe(['queries'])
            ->and($lenses[0]['description'])->toContain('Every executed query');
    });

    it('shows the summary with breakdown, flags and the sections holding data', function (): void {
        $result = toolJson(ShowCapture::class, ['id' => 'latest']);
        $summary = $result['summary'];
        $phaseNames = array_column($summary['breakdown']['phases'], 'name');

        expect($summary['name'])->toBe('GET /things')
            ->and($phaseNames)->toContain('routing', 'controller', 'other')
            ->and(array_column($summary['breakdown']['categories'], 'name'))->toContain('db')
            ->and($summary['totals']['queries'])->toBe(4)
            ->and(implode(' ', array_column($summary['flags'], 'message')))->toContain('N+1')
            ->and($result['available_sections'])->toMatchArray(['queries' => 4, 'request' => 'record'])
            ->and($result['available_sections'])->not->toHaveKey('cache')
            ->and($summary)->not->toHaveKeys(['labels', 'units', 'groups', 'warnings', 'errors', 'counts', 'label', 'parent_id']);
    });

    it('shows the timeline filtered by kind', function (): void {
        $result = toolJson(ShowCapture::class, ['sections' => ['timeline'], 'kinds' => ['phase']]);

        expect(array_unique(array_column($result['timeline'], 'kind')))->toBe(['phase'])
            ->and($result['timeline'][0])->toHaveKeys(['depth', 'start', 'duration']);
    });

    it('returns filtered, projected rows of a section', function (): void {
        $result = toolJson(ShowRows::class, [
            'section' => 'queries',
            'where' => [['field' => 'type', 'op' => '=', 'value' => 'select']],
            'fields' => ['n', 'sql', 'origin'],
            'limit' => 2,
        ]);

        expect($result['total'])->toBe(3)
            ->and($result['returned'])->toBe(2)
            ->and($result['next_offset'])->toBe(2)
            ->and($result['rows'][0])->toHaveKeys(['n', 'sql', 'origin'])
            ->and($result['rows'][0])->not->toHaveKey('bindings')
            ->and($result['rows'][0]['origin'])->toBeString();
    });

    it('aggregates queries by shape and caller', function (): void {
        $byShape = toolJson(ShowQueries::class, ['group_by' => 'shape', 'sort' => 'count']);
        $byCaller = toolJson(ShowQueries::class, ['group_by' => 'caller']);

        expect($byShape['rows'][0]['count'])->toBe(3)
            ->and($byShape['rows'][0]['example'])->toContain('select')
            ->and($byCaller['rows'][0]['key'])->toContain('ToolsTest.php');
    });

    it('shows the trace of a capture', function (): void {
        $result = toolJson(ShowTrace::class, []);

        expect($result['captures'])->toHaveCount(1)
            ->and($result['captures'][0]['depth'])->toBe(0)
            ->and($result['captures'][0]['offset_ms'])->toEqual(0)
            ->and($result['captures'][0])->not->toHaveKey('trace_id')
            ->and($result['trace_id'])->toBeString();
    });

    it('lists every lens section with its advice in the server instructions', function (): void {
        $server = app(MonocleServer::class, ['transport' => new FakeTransporter()]);
        $server->start();

        $instructions = $server->createContext()->instructions;

        expect($instructions)->toContain('`queries`: Look here')
            ->and($instructions)->not->toContain('Every executed query')
            ->and($instructions)->toContain('`cache`:')
            ->and($instructions)->toContain('`middleware`:')
            ->and($instructions)->toContain('monocle-list');
    });

    it('reports missing captures as errors and clears the store', function (): void {
        MonocleServer::tool(ShowCapture::class, ['id' => 'nope'])->assertHasErrors();

        toolJson(ClearCaptures::class, []);

        MonocleServer::tool(ShowCapture::class, [])->assertHasErrors(['No captures recorded yet. Make a request to the application first.']);
    });

    it('lists the enabled lenses with their section keys', function (): void {
        MonocleServer::tool(\ExeQue\Monocle\Mcp\Tools\ListLenses::class)
            ->assertOk()
            ->assertSee(['section', 'queries', 'cache', 'Every executed query']);
    });
});
