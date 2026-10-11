<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Laravel;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
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
final class McpLens implements Advises, Lens, Presentable, RequiresPackages, Summarizable
{
    public function key(): string
    {
        return 'mcp';
    }

    public static function packages(): array
    {
        return ['laravel/mcp'];
    }

    public function description(): string
    {
        return "Calls to this application's own MCP servers: server, JSON-RPC method (tools/call, resources/read, ...), primitive (tool/resource/prompt name), arguments, duration, is_error and streamed_chunks. Over HTTP the call is part of the request capture; over stdio each call is its own capture of type mcp.";
    }

    public function advice(): string
    {
        return 'Look here for calls to the application\'s own MCP servers: which tool ran, with what arguments and how long it took.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        // Laravel/mcp versions without method contracts.
        // @codeCoverageIgnoreStart
        if (! interface_exists(Method::class)) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $except = array_values((array) $eyepiece->config('mcp.except_servers', []));

        $methods = [
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

        foreach ($methods as $method) {
            // Method handler missing from older laravel/mcp versions.
            // @codeCoverageIgnoreStart
            if (! class_exists($method)) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            $app->extend($method, fn (Method $inner): TimedMethod => new TimedMethod(
                inner: $inner instanceof TimedMethod ? $inner->inner() : $inner,
                eyepiece: $eyepiece,
                exceptServers: $except,
            ));
        }
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('mcp');
        $own = $capture->section('mcp');

        if ($rows === [] && $own === []) {
            return Summary::make();
        }

        // A stdio call is a capture of its own, kept as the section rather than as rows.
        $calls = $rows === [] ? [$own] : $rows;
        $errors = array_filter($calls, fn (array $row): bool => (bool) ($row['is_error'] ?? false));
        $durations = array_map(fn (array $row): float => (float) ($row['duration'] ?? 0), $calls);
        $messages = array_map(
            fn (array $row): string => sprintf('MCP %s %s returned an error.', $row['method'] ?? '?', $row['primitive'] ?? ''),
            array_slice(array_values($errors), 0, 3),
        );

        return Summary::make()
            ->total('mcp_ms', round(array_sum($durations), 3), 'spent', Unit::Milliseconds)
            ->total('mcp_calls', count($calls), 'calls')
            ->total('mcp_errors', count($errors), 'errors')
            ->errors($messages);
    }

    public function presentation(): Presentation
    {
        return Presentation::table()->tab('monocle::tabs.mcp');
    }
}
