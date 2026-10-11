<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Prism;

use Closure;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use ExeQue\Monocle\Support\ThrowableChain;
use Generator;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Audio\AudioResponse as TextToSpeechResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as SpeechToTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Moderation\Request as ModerationRequest;
use Prism\Prism\Moderation\Response as ModerationResponse;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\Usage;
use Throwable;
use WeakMap;

/**
 * Decorates a Prism provider: every call becomes a prism.call span and a row
 * in the prism section. Streams are recorded while they are read.
 *
 * @internal
 */
final class TracedProvider extends Provider
{
    /** @var WeakMap<Tool, true>|null Tools whose closure is already timed. */
    private static ?WeakMap $timedTools = null;

    /** How many recorded calls are running: a call or tool call made while one runs is nested in it. */
    private static int $running = 0;

    public function __construct(
        private readonly Provider $inner,
        private readonly Eyepiece $eyepiece,
        private readonly ?int $maxText,
    ) {
    }

    public function inner(): Provider
    {
        return $this->inner;
    }

    public function text(TextRequest $request): TextResponse
    {
        return $this->record('text', $request, fn (): TextResponse => $this->inner->text($request));
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        return $this->record('structured', $request, fn (): StructuredResponse => $this->inner->structured($request));
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return $this->record('embeddings', $request, fn (): EmbeddingsResponse => $this->inner->embeddings($request));
    }

    public function images(ImagesRequest $request): ImagesResponse
    {
        return $this->record('images', $request, fn (): ImagesResponse => $this->inner->images($request));
    }

    public function moderation(ModerationRequest $request): ModerationResponse
    {
        return $this->record('moderation', $request, fn (): ModerationResponse => $this->inner->moderation($request));
    }

    public function textToSpeech(TextToSpeechRequest $request): TextToSpeechResponse
    {
        return $this->record('text_to_speech', $request, fn (): TextToSpeechResponse => $this->inner->textToSpeech($request));
    }

    public function speechToText(SpeechToTextRequest $request): SpeechToTextResponse
    {
        return $this->record('speech_to_text', $request, fn (): SpeechToTextResponse => $this->inner->speechToText($request));
    }

    /**
     * @return Generator<mixed>
     */
    public function stream(TextRequest $request): Generator
    {
        if (! $this->eyepiece->recording()) {
            yield from $this->inner->stream($request);

            return;
        }

        $facts = $this->requestFacts('stream', $request);
        $span = $this->eyepiece->span($this->spanName($facts), 'prism.call')->start($facts);
        $this->timeTools($request);
        $step = null;
        $text = '';

        // Stays abandoned unless the stream is read through to its end event.
        $result = [
            'status' => 'abandoned',
            'streamed' => true,
            'steps' => 0,
            'tool_calls' => 0,
            'failed_tools' => [],
            'finish_reason' => null,
            'tokens' => $this->tokens(null),
        ];

        // The stream runs only while it is read: the caller's own work between two events is not nested in it.
        $running = true;
        self::$running++;

        try {
            foreach ($this->inner->stream($request) as $key => $event) {
                if ($event instanceof StreamStartEvent) {
                    $result['model'] = $event->model;
                }

                if ($event instanceof StepStartEvent) {
                    $step?->stop();
                    $result['steps']++;

                    $number = $result['steps'];
                    $step = $this->eyepiece->span('step '.$number, 'prism.step')->parent($span)->start(['step' => $number]);
                }

                if ($event instanceof StepFinishEvent) {
                    $step?->stop();
                    $step = null;
                }

                if ($event instanceof TextDeltaEvent) {
                    $text .= $event->delta;
                }

                if ($event instanceof ToolCallEvent) {
                    $result['tool_calls']++;
                }

                if ($event instanceof ToolResultEvent && ! $event->success) {
                    $result['failed_tools'][] = $event->toolResult->toolName;
                }

                if ($event instanceof ErrorEvent) {
                    $result['error'] = $event->message;
                }

                if ($event instanceof StreamEndEvent) {
                    $result['status'] = 'ok';
                    $result['finish_reason'] = Normalizer::value($event->finishReason);
                    $result['tokens'] = $this->tokens($event->usage ?? null);
                }

                $running = false;
                self::$running--;

                yield $key => $event;

                $running = true;
                self::$running++;
            }
        } catch (Throwable $exception) {
            $result['status'] = 'failed';
            $result['exception'] = ThrowableChain::describe($exception);

            throw $exception;
        } finally {
            // Also runs when the caller stops reading early, so an abandoned stream is still recorded.
            if ($running) {
                self::$running--;
            }

            $step?->stop();
            $response = $text === '' ? null : $text;

            $this->finish($span, $facts, [...$result, 'response' => $this->truncate($response)]);
        }
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $this->inner->handleRequestException($model, $e);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    public function __get(string $name): mixed
    {
        return $this->inner->{$name};
    }

    /**
     * @template T of object
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function record(string $operation, object $request, Closure $call): object
    {
        if (! $this->eyepiece->recording()) {
            return $call();
        }

        $facts = $this->requestFacts($operation, $request);
        $span = $this->eyepiece->span($this->spanName($facts), 'prism.call')->start($facts);
        $this->timeTools($request);
        self::$running++;

        try {
            $response = $call();
        } catch (Throwable $exception) {
            self::$running--;
            $this->finish($span, $facts, ['status' => 'failed', 'exception' => ThrowableChain::describe($exception)]);

            throw $exception;
        }

        self::$running--;

        $this->finish($span, $facts, ['status' => 'ok', ...$this->responseFacts($response)]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestFacts(string $operation, object $request): array
    {
        return [
            'operation' => $operation,
            'nested' => self::$running > 0,
            'provider' => method_exists($request, 'provider') ? $request->provider() : null,
            'model' => method_exists($request, 'model') ? $request->model() : null,
            'prompt' => $this->truncate($this->prompt($request)),
            'tools' => $this->toolNames($request),
        ];
    }

    /**
     * Prism runs the tools itself and tells no one, so each tool offered with
     * the request gets its closure wrapped once: every call becomes a
     * prism.tool span and a prism_tools row with its time.
     */
    private function timeTools(object $request): void
    {
        if (! method_exists($request, 'tools')) {
            return;
        }

        self::$timedTools ??= new WeakMap();

        foreach ($request->tools() as $tool) {
            if (! $tool instanceof Tool) {
                continue;
            }

            if (isset(self::$timedTools[$tool])) {
                continue;
            }

            self::$timedTools[$tool] = true;
            $run = $this->runTool(...);

            // Tool::$fn is protected; swap it from inside the tool.
            (function () use ($run): void {
                $inner = $this->fn;
                $this->fn = fn (mixed ...$arguments): mixed => $run($this, $inner, $arguments);
            })->call($tool);
        }
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    private function runTool(Tool $tool, callable $inner, array $arguments): mixed
    {
        if (! $this->eyepiece->recording()) {
            return $inner(...$arguments);
        }

        // The call running this tool is itself nested when another call runs around it.
        $span = $this->eyepiece->span($tool->name(), 'prism.tool')->start(['tool' => $tool->name(), 'nested' => self::$running > 1]);
        $started = hrtime(true);

        try {
            $output = $inner(...$arguments);
        } catch (Throwable $exception) {
            $this->recordTool($tool, $span, $arguments, $started, exception: $exception);

            throw $exception;
        }

        $this->recordTool($tool, $span, $arguments, $started, output: $output);

        return $output;
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    private function recordTool(Tool $tool, Span $span, array $arguments, int $started, mixed $output = null, ?Throwable $exception = null): void
    {
        $ms = round((hrtime(true) - $started) / 1_000_000, 3);
        $status = $exception === null ? 'ok' : 'failed';
        $result = $output instanceof ToolOutput ? $output->result : (is_string($output) ? $output : null);
        $masker = $this->eyepiece->masker(PrismLens::class);
        $described = $exception === null ? null : ThrowableChain::describe($exception);

        $span->stop(['status' => $status, 'tool_ms' => $ms]);

        $this->eyepiece->push('prism_tools', [
            'tool' => $tool->name(),
            'nested' => $span->meta['nested'],
            'status' => $status,
            'arguments' => $masker->inputs(Normalizer::normalize($arguments)),
            // A JSON result is masked like input; free text stays as it is.
            'result' => $this->truncate($result === null ? null : $masker->body($result)),
            'exception' => $described,
            'duration' => $ms,
        ]);
    }

    /**
     * The names of the tools offered with the request.
     *
     * @return list<string>
     */
    private function toolNames(object $request): array
    {
        if (! method_exists($request, 'tools')) {
            return [];
        }

        $tools = array_filter($request->tools(), fn (mixed $tool): bool => $tool instanceof Tool);

        return array_map(fn (Tool $tool): string => $tool->name(), array_values($tools));
    }

    private function prompt(object $request): ?string
    {
        if ($request instanceof TextToSpeechRequest) {
            return $request->input();
        }

        if ($request instanceof EmbeddingsRequest) {
            return implode("\n", $request->inputs());
        }

        if (method_exists($request, 'prompt')) {
            return $request->prompt();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFacts(object $response): array
    {
        $facts = [
            'tokens' => $this->tokens($response->usage ?? null),
        ];

        if (isset($response->meta->model)) {
            $facts['model'] = $response->meta->model;
        }

        if (property_exists($response, 'text')) {
            $facts['response'] = $this->truncate($response->text);
        }

        if ($response instanceof StructuredResponse) {
            $facts['structured'] = Normalizer::value($response->structured);
        }

        if (property_exists($response, 'finishReason')) {
            $facts['finish_reason'] = Normalizer::value($response->finishReason);
        }

        if (property_exists($response, 'steps')) {
            $facts['steps'] = count($response->steps);
        }

        if (property_exists($response, 'toolCalls')) {
            $facts['tool_calls'] = count($response->toolCalls);
        }

        return $facts;
    }

    /**
     * @return array{input: int, output: int, cache_read: int, cache_write: int, reasoning: int}
     */
    private function tokens(?object $usage): array
    {
        $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0];

        if ($usage instanceof EmbeddingsUsage) {
            $tokens['input'] = (int) $usage->tokens;
        }

        if ($usage instanceof Usage) {
            $tokens['input'] = $usage->promptTokens;
            $tokens['output'] = $usage->completionTokens;
            $tokens['cache_read'] = (int) $usage->cacheReadInputTokens;
            $tokens['cache_write'] = (int) $usage->cacheWriteInputTokens;
            $tokens['reasoning'] = (int) $usage->thoughtTokens;
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $result
     */
    private function finish(Span $span, array $facts, array $result): void
    {
        $span->stop($result);

        $this->eyepiece->push('prism', [
            'operation' => $facts['operation'],
            'nested' => $facts['nested'],
            'provider' => $facts['provider'],
            'model' => $result['model'] ?? $facts['model'],
            'status' => $result['status'],
            'prompt' => $facts['prompt'],
            'response' => $result['response'] ?? null,
            'structured' => $result['structured'] ?? null,
            'finish_reason' => $result['finish_reason'] ?? null,
            'steps' => $result['steps'] ?? 0,
            'tool_calls' => $result['tool_calls'] ?? 0,
            'tools' => $facts['tools'],
            'failed_tools' => $result['failed_tools'] ?? [],
            'tokens' => $result['tokens'] ?? $this->tokens(null),
            'streamed' => $result['streamed'] ?? false,
            'error' => $result['error'] ?? null,
            'exception' => $result['exception'] ?? null,
            'duration' => $span->duration(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function spanName(array $facts): string
    {
        return trim($facts['operation'].' '.$facts['provider'].'/'.$facts['model'], '/ ');
    }

    private function truncate(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        if ($this->maxText === null) {
            return $text;
        }

        if (mb_strlen($text) <= $this->maxText) {
            return $text;
        }

        return mb_substr($text, 0, $this->maxText).'...';
    }
}
