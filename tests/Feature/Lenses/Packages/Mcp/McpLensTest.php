<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Mcp;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Mcp\McpLens;
use ExeQue\Monocle\Lenses\Packages\Mcp\TimedMethod;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Tests\Fixtures\Mcp\EchoServer;
use Tests\Fixtures\Mcp\EchoTool;
use Tests\Fixtures\Mcp\ExcludedServer;
use Tests\Fixtures\Mcp\StreamTool;

covers(McpLens::class, TimedMethod::class);

describe(McpLens::class, function (): void {
    beforeEach(function (): void {
        Mcp::web('/mcp/echo', EchoServer::class);
        Mcp::web('/mcp/excluded', ExcludedServer::class);
    });

    $call = fn (string $path, array $arguments): TestResponse => test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'echo', 'arguments' => $arguments],
    ], ['Accept' => 'application/json, text/event-stream']);

    it('records tool calls on the request capture over http', function () use ($call): void {
        $call('/mcp/echo', ['text' => 'hi'])->assertOk();
        $capture = latestCapture();
        $rows = $capture->rows('mcp');

        expect($capture->type)->toBe(CaptureType::Request)
            ->and($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['server' => 'echo-server', 'method' => 'tools/call', 'primitive' => 'echo', 'arguments' => ['text' => 'hi'], 'is_error' => false, 'streamed_chunks' => 0])
            ->and($rows[0]['duration'])->toBeNumeric()
            ->and($capture->spansOfKind('mcp'))->toHaveCount(1);

        $call('/mcp/echo', ['text' => 'x', 'fail' => true])->assertOk();
        $summary = app(McpLens::class)->summarize(latestCapture());

        expect($summary['totals'])->toMatchArray(['mcp_calls' => 1, 'mcp_errors' => 1])
            ->and($summary['warnings'])->toBe(['MCP tools/call echo returned an error.']);
    });

    it('skips excluded servers', function () use ($call): void {
        $call('/mcp/excluded', ['text' => 'hi'])->assertOk();

        expect(latestCapture()->rows('mcp'))->toBe([])
            ->and(app(McpLens::class)->summarize(latestCapture()))->toBe([]);
    });

    it('counts streamed chunks', function (): void {
        app(Eyepiece::class)->reset();

        EchoServer::tool(StreamTool::class, [])->assertOk();

        $capture = latestCapture();

        expect($capture->section('mcp')['streamed_chunks'])->toBe(2)
            ->and($capture->section('mcp')['is_error'])->toBeFalse()
            ->and($capture->spansOfKind('mcp')[0]->meta['chunks'])->toBe(2);
    });

    it('turns stdio calls into captures of their own', function (): void {
        app(Eyepiece::class)->reset();

        EchoServer::tool(EchoTool::class, ['text' => 'standalone'])->assertOk();

        $capture = latestCapture();

        expect($capture->type)->toBe(CaptureType::Mcp)
            ->and($capture->name)->toBe('echo-server: tools/call echo')
            ->and($capture->status)->toBe('ok')
            ->and($capture->section('mcp')['primitive'])->toBe('echo')
            ->and(app(CaptureReader::class)->list(Filter::fromArray(['type' => 'mcp']))['total'])->toBe(1);
    });
});
