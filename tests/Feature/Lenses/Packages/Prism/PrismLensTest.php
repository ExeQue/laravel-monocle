<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Prism;

use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Prism\PrismLens;
use ExeQue\Monocle\Lenses\Packages\Prism\TracedPrismManager;
use ExeQue\Monocle\Lenses\Packages\Prism\TracedProvider;
use Generator;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

covers(PrismLens::class, TracedPrismManager::class, TracedProvider::class);

describe(PrismLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    afterEach(function (): void {
        app(Eyepiece::class)->reset();
    });

    it('records a text call with tokens and a span', function (): void {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello Ada!')
                ->withToolCalls([new ToolCall('call-1', 'weather', ['city' => 'Aarhus'])])
                ->withUsage(new Usage(100, 20, 3, 5, 2))
                ->withMeta(new Meta('resp-1', 'gpt-4o-2024')),
        ]);

        $response = Prism::text()->using('openai', 'gpt-4o')->withPrompt('Say hi to Ada')->asText();

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('prism');
        $span = $capture->spansOfKind('prism.call')[0];

        expect($response->text)->toBe('Hello Ada!')
            ->and($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray([
                'operation' => 'text',
                'provider' => 'openai',
                'model' => 'gpt-4o-2024',
                'status' => 'ok',
                'prompt' => 'Say hi to Ada',
                'response' => 'Hello Ada!',
                'finish_reason' => 'stop',
                'tool_calls' => 1,
                'streamed' => false,
                'tokens' => ['input' => 100, 'output' => 20, 'cache_read' => 5, 'cache_write' => 3, 'reasoning' => 2],
            ])
            ->and($rows[0]['duration'])->toBeNumeric()
            ->and($span->name)->toBe('text openai/gpt-4o')
            ->and($span->meta['status'])->toBe('ok');
    });

    it('records structured output', function (): void {
        Prism::fake([
            StructuredResponseFake::make()->withStructured(['name' => 'Ada'])->withUsage(new Usage(10, 5)),
        ]);

        Prism::structured()
            ->using('anthropic', 'claude-test')
            ->withSchema(new ObjectSchema('person', 'A person', [new StringSchema('name', 'The name')], ['name']))
            ->withPrompt('Who wrote the first program?')
            ->asStructured();

        $row = app(Eyepiece::class)->finalize()->rows('prism')[0];

        expect($row)->toMatchArray(['operation' => 'structured', 'provider' => 'anthropic', 'structured' => ['name' => 'Ada'], 'status' => 'ok'])
            ->and($row['tokens']['output'])->toBe(5);
    });

    it('records embeddings with their tokens', function (): void {
        Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray([0.1, 0.2])])->withMeta(new Meta('emb-1', 'embed-small')),
        ]);

        Prism::embeddings()->using('openai', 'text-embedding-3-small')->fromInput('Ada Lovelace')->asEmbeddings();

        $row = app(Eyepiece::class)->finalize()->rows('prism')[0];

        expect($row)->toMatchArray(['operation' => 'embeddings', 'model' => 'embed-small', 'prompt' => 'Ada Lovelace', 'status' => 'ok']);
    });

    it('records a stream with its steps, text and usage while it is read', function (): void {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello there, Ada!')
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(30, 7)),
        ]);

        $text = '';

        foreach (Prism::text()->using('openai', 'gpt-4o')->withPrompt('Say hi')->asStream() as $event) {
            if ($event instanceof TextDeltaEvent) {
                $text .= $event->delta;
            }
        }

        $capture = app(Eyepiece::class)->finalize();
        $row = $capture->rows('prism')[0];
        $call = $capture->spansOfKind('prism.call')[0];
        $steps = $capture->spansOfKind('prism.step');

        expect($text)->toBe('Hello there, Ada!')
            ->and($row)->toMatchArray(['operation' => 'stream', 'status' => 'ok', 'streamed' => true, 'response' => 'Hello there, Ada!', 'steps' => 1, 'finish_reason' => 'stop'])
            ->and($row['tokens'])->toMatchArray(['input' => 30, 'output' => 7])
            ->and($steps)->toHaveCount(1)
            ->and($steps[0]->parentId)->toBe($call->id);
    });

    it('records failed tools and abandoned streams on a real manager', function (): void {
        app(PrismManager::class)->extend('scripted', fn (): Provider => new class () extends Provider {
            public function stream(TextRequest $request): Generator
            {
                yield new StreamStartEvent('e1', 0, 'scripted-model', 'scripted');
                yield new StepStartEvent('e2', 0);
                yield new ToolResultEvent('e3', 0, new ToolResult('t1', 'search', [], null), 'm1', false, 'boom');
                yield new StepFinishEvent('e4', 0);
                yield new StreamEndEvent('e5', 0, FinishReason::Stop, new Usage(4, 2));
            }
        });

        iterator_to_array(Prism::text()->using('scripted', 'm')->withPrompt('full')->asStream(), false);

        foreach (Prism::text()->using('scripted', 'm')->withPrompt('partial')->asStream() as $event) {
            break;
        }

        $capture = app(Eyepiece::class)->finalize();
        $rows = collect($capture->rows('prism'))->keyBy('prompt');

        expect($rows['full'])->toMatchArray(['status' => 'ok', 'model' => 'scripted-model', 'failed_tools' => ['search']])
            ->and($rows['partial']['status'])->toBe('abandoned')
            ->and(implode(' ', app(PrismLens::class)->summarize($capture)->errors))->toContain('Tool search failed during Prism stream');
    });

    it('records a failing call as failed and reports it as an error', function (): void {
        app(PrismManager::class)->extend('broken', fn (): Provider => new class () extends Provider {
            public function text(TextRequest $request): TextResponse
            {
                throw new RuntimeException('provider down');
            }
        });

        expect(fn () => Prism::text()->using('broken', 'm')->withPrompt('hi')->asText())->toThrow(RuntimeException::class, 'provider down');

        $capture = app(Eyepiece::class)->finalize();
        $row = $capture->rows('prism')[0];

        expect($row['status'])->toBe('failed')
            ->and($row['exception']['message'])->toBe('provider down')
            ->and($capture->spansOfKind('prism.call')[0]->meta['status'])->toBe('failed')
            ->and(app(PrismLens::class)->summarize($capture)->errors)->toBe(['Prism text on broken/m failed: provider down']);
    });

    it('summarizes time, calls, tokens and models', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('one')->withUsage(new Usage(10, 1))->withMeta(new Meta('a', 'gpt-4o')),
            TextResponseFake::make()->withText('two')->withUsage(new Usage(20, 2))->withMeta(new Meta('b', 'gpt-4o')),
        ]);

        Prism::text()->using('openai', 'gpt-4o')->withPrompt('1')->asText();
        Prism::text()->using('openai', 'gpt-4o')->withPrompt('2')->asText();

        $summary = app(PrismLens::class)->summarize(app(Eyepiece::class)->finalize());

        expect(array_key_first($summary->totals))->toBe('prism_ms')
            ->and($summary->totals['prism_calls'])->toBe(2)
            ->and($summary->totals['prism_tokens'])->toBe(['input' => 30, 'output' => 3, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0])
            ->and($summary->totals['prism_by_model'])->toBe(['openai/gpt-4o' => 2])
            ->and($summary->errors)->toBe([]);
    });

    it('keeps tracing after Prism::fake() swaps the manager', function (): void {
        expect(app(PrismManager::class))->toBeInstanceOf(TracedPrismManager::class);

        $fake = Prism::fake();

        expect(app(PrismManager::class))->toBeInstanceOf(TracedPrismManager::class)
            ->and(Prism::provider('openai'))->toBeInstanceOf(TracedProvider::class);

        Prism::text()->using('openai', 'gpt-4o')->withPrompt('hi')->asText();

        $fake->assertCallCount(1);
        expect(app(Eyepiece::class)->finalize()->rows('prism'))->toHaveCount(1);
    });

    it('truncates text when configured and records nothing outside a capture', function (): void {
        $lens = app(PrismLens::class);
        $lens->configure(['max_text' => 5]);
        $lens->boot($this->app);

        Prism::fake([TextResponseFake::make()->withText('A very long answer')]);
        Prism::text()->using('openai', 'gpt-4o')->withPrompt('Say hi to Ada')->asText();

        $row = app(Eyepiece::class)->finalize()->rows('prism')[0];

        expect($row)->toMatchArray(['prompt' => 'Say h...', 'response' => 'A ver...']);

        app(Eyepiece::class)->reset();
        Prism::fake([TextResponseFake::make()->withText('quiet')]);
        $response = Prism::text()->using('openai', 'gpt-4o')->withPrompt('hi')->asText();
        app(Eyepiece::class)->start(CaptureType::Request);

        expect($response->text)->toBe('quiet')
            ->and(app(PrismLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(Summary::make());
    });

    it('overrides every public method a Prism provider declares', function (): void {
        $declared = array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(Provider::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach ($declared as $method) {
            expect((new ReflectionMethod(TracedProvider::class, $method))->getDeclaringClass()->getName())
                ->toBe(TracedProvider::class, "TracedProvider does not override {$method}()");
        }
    });

    it('presents its rows on the prism tab', function (): void {
        expect(app(PrismLens::class)->presentation()->tabView)->toBe('monocle::tabs.prism');
    });
});
