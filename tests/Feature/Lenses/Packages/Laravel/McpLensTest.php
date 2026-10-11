<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Laravel;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Laravel\McpLens;
use ExeQue\Monocle\Lenses\Packages\Laravel\TimedMethod;
use ExeQue\Monocle\Reader\CaptureReader;
use ExeQue\Monocle\Reader\Filter;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Schema\Implementation;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Mockery;
use RuntimeException;
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

        expect($summary->totals)->toMatchArray(['mcp_calls' => 1, 'mcp_errors' => 1])
            ->and($summary->errors)->toBe(['MCP tools/call echo returned an error.']);
    });

    it('skips excluded servers', function () use ($call): void {
        $call('/mcp/excluded', ['text' => 'hi'])->assertOk();

        expect(latestCapture()->rows('mcp'))->toBe([])
            ->and(app(McpLens::class)->summarize(latestCapture()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
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

    it('records a handler that throws as a failed call and rethrows', function (): void {
        app(Eyepiece::class)->reset();
        $inner = Mockery::mock(Method::class);
        $inner->shouldReceive('handle')->andThrow(new RuntimeException('tool crashed'));
        $method = new TimedMethod($inner, app(Eyepiece::class), []);

        expect($method->inner())->toBe($inner)
            ->and(fn () => $method->handle(new JsonRpcRequest(1, 'tools/call', ['name' => 'echo']), mcpContext()))
            ->toThrow(RuntimeException::class, 'tool crashed');

        $capture = latestCapture();

        expect($capture->status)->toBe('error')
            ->and($capture->section('mcp')['is_error'])->toBeTrue()
            ->and($capture->section('mcp')['exception']['message'])->toBe('tool crashed');
    });

    it('marks a JSON-RPC error response as an error', function (): void {
        app(Eyepiece::class)->reset();
        $inner = Mockery::mock(Method::class);
        $inner->shouldReceive('handle')->andReturn(JsonRpcResponse::error(1, -32601, 'Method not found'));

        new TimedMethod($inner, app(Eyepiece::class), [])->handle(new JsonRpcRequest(1, 'prompts/get', ['name' => 'missing']), mcpContext());

        expect(latestCapture()->section('mcp'))->toMatchArray(['method' => 'prompts/get', 'primitive' => 'missing', 'is_error' => true]);
    });

    it('describes its rows and shows them on the mcp tab', function (): void {
        $lens = app(McpLens::class);

        expect($lens->description())->toContain('JSON-RPC method')
            ->and($lens->advice())->toStartWith('Look here for calls')
            ->and($lens->presentation()->tabView)->toBe('monocle::tabs.mcp');
    });
});

function mcpContext(): ServerContext
{
    return new ServerContext(
        supportedProtocolVersions: ['2025-06-18'],
        serverCapabilities: [],
        implementation: new Implementation('direct-server', '1.0.0'),
        instructions: '',
        maxPaginationLength: 50,
        defaultPaginationLength: 15,
        tools: [],
        resources: [],
        prompts: [],
    );
}
