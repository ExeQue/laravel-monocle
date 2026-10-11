<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Laravel;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Laravel\AiLens;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Mockery;
use RuntimeException;
use Tests\Fixtures\Ai\BrokenNameTool;
use Tests\Fixtures\Ai\Greeter;
use Tests\Fixtures\Ai\WeatherTool;

covers(AiLens::class);

describe(AiLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        $this->provider = Mockery::mock(TextProvider::class)->shouldIgnoreMissing();
        $this->provider->shouldReceive('name')->andReturn('openai');

        $this->prompt = fn (string $invocationId, ?string $parent = null, ?string $parentTool = null): AgentPrompt => new AgentPrompt(
            new Greeter(),
            'Say hi to Ada',
            [],
            $this->provider,
            'gpt-test',
            null,
            $invocationId,
            null,
            $parent,
            $parentTool,
        );
    });

    it('records a faked agent run end to end', function (): void {
        Greeter::fake(['Hello Ada!']);

        $response = (new Greeter())->prompt('Say hi to Ada');

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('ai');
        $span = $capture->spansOfKind('ai.agent')[0];

        expect((string) $response)->toBe('Hello Ada!')
            ->and($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['agent' => Greeter::class, 'status' => 'ok', 'prompt' => 'Say hi to Ada', 'response' => 'Hello Ada!', 'streamed' => false])
            ->and($rows[0]['duration'])->toBeNumeric()
            ->and($span->name)->toBe('Greeter')
            ->and($span->meta['status'])->toBe('ok');
    });

    it('builds the agent -> step -> tool tree with tokens and nested agents', function (): void {
        $agent = new Greeter();
        $tool = new WeatherTool();
        $usage = new TextUsage(100, 20, 5, null, 2);
        $step = new StepResponse('partial', ['call'], FinishReason::ToolCalls, $usage, new Meta('openai', 'gpt-test'));

        event(new PromptingAgent('inv-1', ($this->prompt)('inv-1')));
        event(new StartingStep('inv-1', 1, $agent, $this->provider, 'gpt-test', false, [['role' => 'user']], null));
        event(new InvokingTool('inv-1', 'tool-1', $agent, $tool, ['city' => 'Aarhus']));
        event(new PromptingAgent('inv-2', ($this->prompt)('inv-2', 'inv-1', 'tool-1')));
        event(new AgentPrompted('inv-2', ($this->prompt)('inv-2', 'inv-1', 'tool-1'), new AgentResponse('inv-2', 'nested done', new TextUsage(7, 3), new Meta('openai', 'gpt-test'))));
        event(new ToolInvoked('inv-1', 'tool-1', $agent, $tool, ['city' => 'Aarhus'], 'sunny', 42.0));
        event(new InvokingTool('inv-1', 'tool-2', $agent, $tool, []));
        event(new ToolFailed('inv-1', 'tool-2', $agent, $tool, [], new RuntimeException('tool broke'), 1.5));
        event(new StepCompleted('inv-1', 1, $agent, $this->provider, 'gpt-test', false, $step, 250.0));
        event(new StartingStep('inv-1', 2, $agent, $this->provider, 'gpt-test', true, [], null));
        event(new StepFailed('inv-1', 2, $agent, $this->provider, 'gpt-test', true, new RuntimeException('provider down'), 10.0));
        event(new AgentFailed('inv-1', ($this->prompt)('inv-1'), new RuntimeException('gave up')));

        $capture = app(Eyepiece::class)->finalize();
        $rows = collect($capture->rows('ai'))->keyBy('invocation_id');
        $agents = collect($capture->spansOfKind('ai.agent'))->keyBy(fn ($span) => $span->meta['invocation_id']);
        $steps = $capture->spansOfKind('ai.step');
        $tools = collect($capture->spansOfKind('ai.tool'))->keyBy(fn ($span) => $span->meta['tool_invocation_id']);

        expect($rows['inv-1'])->toMatchArray(['status' => 'failed', 'steps' => 2, 'tool_calls' => 2, 'failed_tools' => ['weather'], 'provider' => 'openai', 'model' => 'gpt-test'])
            ->and($rows['inv-1']['tokens'])->toBe(['input' => 100, 'output' => 20, 'cache_read' => 5, 'cache_write' => 0, 'reasoning' => 2])
            ->and($rows['inv-1']['exception']['message'])->toBe('gave up')
            ->and($rows['inv-2'])->toMatchArray(['status' => 'ok', 'parent_invocation_id' => 'inv-1', 'response' => 'nested done'])
            ->and($rows['inv-2']['tokens']['input'])->toBe(7)
            ->and($steps[0]->parentId)->toBe($agents['inv-1']->id)
            ->and($steps[0]->meta)->toMatchArray(['step' => 1, 'messages' => 1, 'tool_calls' => 1, 'finish_reason' => 'tool_calls', 'provider_ms' => 250.0, 'status' => 'ok'])
            ->and($steps[1]->meta)->toMatchArray(['status' => 'failed', 'provider_ms' => 10.0])
            ->and($tools['tool-1']->parentId)->toBe($steps[0]->id)
            ->and($tools['tool-1']->name)->toBe('weather')
            ->and($tools['tool-1']->meta)->toMatchArray(['arguments' => ['city' => 'Aarhus'], 'result' => 'sunny', 'status' => 'ok', 'tool_ms' => 42.0])
            ->and($tools['tool-2']->meta['status'])->toBe('failed')
            ->and($agents['inv-2']->parentId)->toBe($tools['tool-1']->id);

        $summary = app(AiLens::class)->summarize($capture);

        expect($summary->totals['ai_calls'])->toBe(2)
            ->and($summary->totals['ai_tokens']['input'])->toBe(107)
            ->and($summary->totals['ai_by_model'])->toBe(['openai/gpt-test' => 2])
            ->and(implode(' ', $summary->errors))->toContain('gave up')->toContain('Tool weather failed');
    });

    it('truncates text when configured and ignores events outside a capture', function (): void {
        $lens = app(AiLens::class);
        $lens->configure(['max_text' => 5]);
        $lens->boot($this->app, app(Eyepiece::class));

        Greeter::fake(['A very long answer', 'Yo']);
        (new Greeter())->prompt('Say hi to Ada');
        (new Greeter())->prompt('Hi');

        $rows = app(Eyepiece::class)->finalize()->rows('ai');
        $truncated = collect($rows)->firstWhere('response', 'A ver...');

        expect($truncated)->not->toBeNull()
            ->and($truncated['prompt'])->toBe('Say h...')
            ->and(collect($rows)->firstWhere('response', 'Yo'))->not->toBeNull();

        app(Eyepiece::class)->reset();
        event(new PromptingAgent('inv-9', ($this->prompt)('inv-9')));
        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(AiLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });
    it('ignores agent, step and tool events for invocations it is not tracking', function (): void {
        $agent = new Greeter();
        $tool = new WeatherTool();
        $usage = new TextUsage(9, 9);
        $step = new StepResponse('late', [], FinishReason::Stop, $usage, new Meta('openai', 'gpt-test'));

        event(new AgentPrompted('ghost', ($this->prompt)('ghost'), new AgentResponse('ghost', 'boo', $usage, new Meta('openai', 'gpt-test'))));
        event(new AgentFailed('ghost', ($this->prompt)('ghost'), new RuntimeException('boo')));
        event(new StartingStep('ghost', 1, $agent, $this->provider, 'gpt-test', true, [], null));
        event(new InvokingTool('ghost', 'ghost-tool', $agent, $tool, []));

        event(new PromptingAgent('inv-1', ($this->prompt)('inv-1')));
        event(new StartingStep('inv-1', 1, $agent, $this->provider, 'gpt-test', true, [], null));
        event(new AgentFailed('inv-1', ($this->prompt)('inv-1'), new RuntimeException('gave up')));
        event(new StepCompleted('inv-1', 1, $agent, $this->provider, 'gpt-test', true, $step, 5.0));

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('ai');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['invocation_id'])->toBe('inv-1')
            ->and($rows[0]['tokens'])->toBe(['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0])
            ->and($capture->spansOfKind('ai.step'))->toHaveCount(1)
            ->and($capture->spansOfKind('ai.tool'))->toBe([]);
    });

    it('puts a tool under the open step of its own agent and copes with names and providers that throw', function (): void {
        $agent = new Greeter();
        $broken = Mockery::mock(TextProvider::class)->shouldIgnoreMissing();
        $broken->shouldReceive('name')->andThrow(new RuntimeException('no name'));
        $prompt = new class (new Greeter(), 'Short prompt', [], $this->provider, 'gpt-test', null, 'inv-a') extends AgentPrompt {
            public function provider(): TextProvider
            {
                throw new RuntimeException('no provider');
            }
        };

        event(new PromptingAgent('inv-a', $prompt));
        event(new StartingStep('inv-a', 1, $agent, $broken, 'gpt-test', false, [], null));
        event(new PromptingAgent('inv-b', ($this->prompt)('inv-b')));
        event(new StartingStep('inv-b', 1, $agent, $this->provider, 'gpt-test', false, [], null));
        event(new InvokingTool('inv-a', 'tool-a', $agent, new BrokenNameTool(), []));
        event(new ToolInvoked('inv-a', 'tool-a', $agent, new BrokenNameTool(), [], ['temp' => 21], 2.0));

        $capture = app(Eyepiece::class)->finalize();
        $agents = collect($capture->spansOfKind('ai.agent'))->keyBy(fn ($span) => $span->meta['invocation_id']);
        $steps = collect($capture->spansOfKind('ai.step'))->keyBy(fn ($span) => $span->parentId);
        $tool = $capture->spansOfKind('ai.tool')[0];

        expect($agents['inv-a']->meta)->toMatchArray(['provider' => null, 'prompt' => 'Short prompt'])
            ->and($steps[$agents['inv-a']->id]->meta['provider'])->toBe($broken::class)
            ->and($tool->parentId)->toBe($steps[$agents['inv-a']->id]->id)
            ->and($tool->name)->toBe('broken_name_tool')
            ->and($tool->meta['result'])->toBe('{"temp":21}');
    });
});
