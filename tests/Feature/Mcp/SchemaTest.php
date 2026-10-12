<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use ExeQue\Monocle\Mcp\MonocleServer;
use ExeQue\Monocle\Mcp\Tools\ClearCaptures;
use ExeQue\Monocle\Mcp\Tools\CompareCaptures;
use ExeQue\Monocle\Mcp\Tools\Concerns\DescribesPagination;
use ExeQue\Monocle\Mcp\Tools\Concerns\RespondsWithJson;
use ExeQue\Monocle\Mcp\Tools\ListCaptures;
use ExeQue\Monocle\Mcp\Tools\ListLenses;
use ExeQue\Monocle\Mcp\Tools\ShowCapture;
use ExeQue\Monocle\Mcp\Tools\ShowQueries;
use ExeQue\Monocle\Mcp\Tools\ShowRows;
use ExeQue\Monocle\Mcp\Tools\ShowTrace;
use Laravel\Mcp\Server\Transport\FakeTransporter;

covers(MonocleServer::class, ListCaptures::class, ShowCapture::class, ShowRows::class, ShowQueries::class, ShowTrace::class, CompareCaptures::class, ClearCaptures::class, ListLenses::class, DescribesPagination::class, RespondsWithJson::class);

describe('MCP tool schemas', function (): void {
    it('advertises every tool with a name, a description and an input schema', function (): void {
        $server = app(MonocleServer::class, ['transport' => new FakeTransporter()]);
        $server->start();

        $tools = $server->createContext()->tools()->map(fn ($tool): array => $tool->toArray())->keyBy('name');

        expect($tools->keys()->all())->toBe(['monocle-list', 'monocle-show', 'monocle-rows', 'monocle-queries', 'monocle-trace', 'monocle-compare', 'monocle-clear', 'monocle-lenses']);

        foreach ($tools as $name => $tool) {
            expect($tool['description'])->toBeString()->not->toBeEmpty("{$name} has no description");
        }

        expect($tools['monocle-rows']['inputSchema']['properties'])->toHaveKeys(['section', 'offset', 'limit', 'sort', 'dir', 'where', 'fields', 'verbose'])
            ->and($tools['monocle-rows']['inputSchema']['required'])->toBe(['section'])
            ->and($tools['monocle-rows']['inputSchema']['properties']['where']['items']['properties']['op']['enum'])->toContain('contains', 'in')
            ->and($tools['monocle-list']['inputSchema']['properties'])->toHaveKeys(['type', 'uri', 'host', 'status', 'min_duration_ms', 'since', 'label', 'has_errors', 'has', 'offset', 'limit'])
            ->and($tools['monocle-list']['inputSchema']['properties']['type']['enum'])->toBe(['request', 'job', 'command', 'mcp'])
            ->and($tools['monocle-show']['inputSchema']['properties'])->toHaveKeys(['id', 'sections', 'limit', 'kinds', 'verbose'])
            ->and($tools['monocle-queries']['inputSchema']['properties']['group_by']['enum'])->toBe(['shape', 'caller', 'table', 'transaction'])
            ->and($tools['monocle-compare']['inputSchema']['required'])->toBe(['a'])
            ->and($tools['monocle-trace']['inputSchema']['properties'])->toHaveKey('id')
            ->and($tools['monocle-lenses']['inputSchema']['properties'])->toHaveKey('sections')
            ->and($tools['monocle-clear']['annotations']['destructiveHint'] ?? null)->toBeTrue()
            ->and($tools['monocle-list']['annotations']['readOnlyHint'] ?? null)->toBeTrue();
    });
});
