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
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/**
 * Decorates a Prism provider: every call becomes a prism.call span and a row
 * in the prism section. Streams are recorded while they are read.
 *
 * @internal
 */
final class TracedProvider extends Provider
{
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
        $step = null;
        $text = '';
        $result = [
            'status' => 'abandoned',
            'streamed' => true,
            'steps' => 0,
            'tool_calls' => 0,
            'failed_tools' => [],
            'finish_reason' => null,
            'tokens' => $this->tokens(null),
        ];

        try {
            foreach ($this->inner->stream($request) as $key => $event) {
                if ($event instanceof StreamStartEvent) {
                    $result['model'] = $event->model;
                }

                if ($event instanceof StepStartEvent) {
                    $step?->stop();
                    $result['steps']++;
                    $step = $this->eyepiece->span('step '.$result['steps'], 'prism.step')->parent($span)->start(['step' => $result['steps']]);
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

                yield $key => $event;
            }
        } catch (Throwable $exception) {
            $result['status'] = 'failed';
            $result['exception'] = ThrowableChain::describe($exception);

            throw $exception;
        } finally {
            $step?->stop();
            $this->finish($span, $facts, [...$result, 'response' => $this->truncate($text === '' ? null : $text)]);
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

        try {
            $response = $call();
        } catch (Throwable $exception) {
            $this->finish($span, $facts, ['status' => 'failed', 'exception' => ThrowableChain::describe($exception)]);

            throw $exception;
        }

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
            'provider' => method_exists($request, 'provider') ? $request->provider() : null,
            'model' => method_exists($request, 'model') ? $request->model() : null,
            'prompt' => $this->truncate($this->prompt($request)),
            'tools' => method_exists($request, 'tools') ? array_map(fn (Tool $tool): string => $tool->name(), array_values(array_filter($request->tools(), fn (mixed $tool): bool => $tool instanceof Tool))) : [],
        ];
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
