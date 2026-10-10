<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Mcp\TimedMethod;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\Methods\CompletionComplete;
use Laravel\Mcp\Server\Methods\Discover;
use Laravel\Mcp\Server\Methods\GetPrompt;
use Laravel\Mcp\Server\Methods\Initialize;
use Laravel\Mcp\Server\Methods\ListPrompts;
use Laravel\Mcp\Server\Methods\ListResources;
use Laravel\Mcp\Server\Methods\ListResourceTemplates;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Methods\Ping;
use Laravel\Mcp\Server\Methods\ReadResource;

/**
 * Records calls to the MCP servers this application exposes to its own
 * users. laravel/mcp resolves method handlers through the container, so the
 * handlers are decorated there. Monocle's own server and Boost's are skipped.
 */
final class McpLens implements Lens, Summarizable
{
    private const array METHODS = [
        CallTool::class,
        ReadResource::class,
        GetPrompt::class,
        ListTools::class,
        ListResources::class,
        ListResourceTemplates::class,
        ListPrompts::class,
        CompletionComplete::class,
        Discover::class,
        Initialize::class,
        Ping::class,
    ];

    public function key(): string
    {
        return 'mcp';
    }

    public function description(): string
    {
        return "Calls to this application's own MCP servers: server, JSON-RPC method (tools/call, resources/read, ...), primitive (tool/resource/prompt name), arguments, duration, is_error and streamed_chunks. Over HTTP the call is part of the request capture; over stdio each call is its own capture of type mcp.";
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        if (! interface_exists(Method::class)) {
            return;
        }

        $except = array_values((array) $eyepiece->config('mcp.except_servers', []));

        foreach (self::METHODS as $method) {
            if (! class_exists($method)) {
                continue;
            }

            $app->extend($method, fn (Method $inner): TimedMethod => new TimedMethod(
                inner: $inner instanceof TimedMethod ? $inner->inner() : $inner,
                eyepiece: $eyepiece,
                exceptServers: $except,
            ));
        }
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('mcp');
        $own = $capture->section('mcp');

        if ($rows === [] && $own === []) {
            return [];
        }

        $calls = $rows === [] ? [$own] : $rows;
        $errors = array_filter($calls, fn (array $row): bool => (bool) ($row['is_error'] ?? false));

        return [
            'totals' => [
                'mcp_calls' => count($calls),
                'mcp_errors' => count($errors),
                'mcp_ms' => round(array_sum(array_map(fn (array $row): float => (float) ($row['duration'] ?? 0), $calls)), 3),
            ],
            'warnings' => array_map(
                fn (array $row): string => sprintf('MCP %s %s returned an error.', $row['method'] ?? '?', $row['primitive'] ?? ''),
                array_slice(array_values($errors), 0, 3),
            ),
        ];
    }
}
