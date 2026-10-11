<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Mcp;

use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use Generator;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * Decorates a laravel/mcp method handler. Over HTTP the call lands as a span
 * and a row on the request capture; over stdio every JSON-RPC request becomes
 * its own capture of type mcp.
 */
final class TimedMethod implements Errable, Method
{
    /**
     * @param  list<string>  $exceptServers
     */
    public function __construct(
        private readonly Method $inner,
        private readonly Eyepiece $eyepiece,
        private readonly array $exceptServers,
    ) {
    }

    public function handle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $server = $context->implementation->name;

        if (in_array(strtolower($server), array_map('strtolower', $this->exceptServers), true)) {
            return $this->inner->handle($request, $context);
        }

        $facts = [
            'server' => $server,
            'method' => $request->method,
            'primitive' => $this->primitive($request),
            'arguments' => $request->params['arguments'] ?? null,
            'protocol_version' => $request->params['protocolVersion'] ?? null,
        ];

        $ownCapture = $this->eyepiece->active() === null;

        if ($ownCapture) {
            $this->eyepiece->start(CaptureType::Mcp);
            $this->eyepiece->describe(name: trim("{$server}: {$request->method} ".($facts['primitive'] ?? '')));
            $this->eyepiece->set('mcp', $facts);
        }

        $span = $this->eyepiece->span(trim($request->method.' '.($facts['primitive'] ?? '')), 'mcp')->start($facts);
        $chunks = 0;

        try {
            $response = $this->inner->handle($request, $context);
        } catch (Throwable $exception) {
            $this->finish($span, $facts, $ownCapture, isError: true, chunks: 0, exception: $exception);

            throw $exception;
        }

        if ($response instanceof JsonRpcResponse) {
            $this->finish($span, $facts, $ownCapture, isError: $this->isError($response), chunks: 0);

            return $response;
        }

        return $this->stream($response, $span, $facts, $ownCapture);
    }

    public function inner(): Method
    {
        return $this->inner;
    }

    /**
     * @param  iterable<JsonRpcResponse>  $responses
     * @return Generator<JsonRpcResponse>
     */
    private function stream(iterable $responses, Span $span, array $facts, bool $ownCapture): Generator
    {
        $chunks = 0;
        $isError = false;

        try {
            foreach ($responses as $response) {
                $chunks++;
                $isError = $isError || ($response instanceof JsonRpcResponse && $this->isError($response));

                yield $response;
            }
        } finally {
            $this->finish($span, $facts, $ownCapture, isError: $isError, chunks: $chunks);
        }
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function finish(Span $span, array $facts, bool $ownCapture, bool $isError, int $chunks, ?Throwable $exception = null): void
    {
        $span->stop(['is_error' => $isError, 'chunks' => $chunks]);

        $row = [
            ...$facts,
            'duration' => $span->duration(),
            'is_error' => $isError,
            'streamed_chunks' => $chunks,
            'exception' => $exception === null ? null : ['class' => $exception::class, 'message' => $exception->getMessage()],
        ];

        if ($ownCapture) {
            $this->eyepiece->set('mcp', $row);
            $this->eyepiece->describe(status: $isError || $exception !== null ? 'error' : 'ok');
            $this->eyepiece->finalize();

            return;
        }

        $this->eyepiece->push('mcp', $row, origin: false);
    }

    private function primitive(JsonRpcRequest $request): ?string
    {
        $name = $request->params['name'] ?? $request->params['uri'] ?? null;

        return is_string($name) ? $name : null;
    }

    private function isError(JsonRpcResponse $response): bool
    {
        $content = $response->toArray();

        return array_key_exists('error', $content) || (bool) ($content['result']['isError'] ?? false);
    }
}
