<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses\Packages\Laravel;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Packages\Laravel\AiLens;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Tools\AgentTool;
use Mockery;
use RuntimeException;
use Tests\Fixtures\Ai\Greeter;
use Tests\Fixtures\Ai\WeatherTool;

covers(AiLens::class);

describe(AiLens::class, function (): void {
    beforeEach(function (): void {
        app(Eyepiece::class)->start(CaptureType::Request);

        $provider = Mockery::mock(TextProvider::class)->shouldIgnoreMissing();
        $provider->shouldReceive('name')->andReturn('openai');

        $this->prompt = new AgentPrompt(new Greeter(), 'Weather?', [], $provider, 'gpt-test', null, 'inv-1');
        $this->agent = new Greeter();
        $this->tool = new WeatherTool();
    });

    it('records each tool call with its arguments, result, status and time', function (): void {
        event(new PromptingAgent('inv-1', $this->prompt));
        event(new InvokingTool('inv-1', 'tool-1', $this->agent, $this->tool, ['city' => 'Aarhus']));
        event(new ToolInvoked('inv-1', 'tool-1', $this->agent, $this->tool, ['city' => 'Aarhus'], 'sunny', 42.0));
        event(new InvokingTool('inv-1', 'tool-2', $this->agent, $this->tool, ['city' => 'Oslo']));
        event(new ToolFailed('inv-1', 'tool-2', $this->agent, $this->tool, ['city' => 'Oslo'], new RuntimeException('no forecast'), 1.5));
        event(new AgentPrompted('inv-1', $this->prompt, new AgentResponse('inv-1', 'done', new TextUsage(1, 1), new Meta('openai', 'gpt-test'))));

        $capture = app(Eyepiece::class)->finalize();
        $tools = $capture->rows('ai_tools');

        expect($tools)->toHaveCount(2)
            ->and($tools[0])->toMatchArray([
                'tool' => 'weather',
                'class' => WeatherTool::class,
                'sub_agent' => false,
                'agent' => Greeter::class,
                'invocation_id' => 'inv-1',
                'tool_invocation_id' => 'tool-1',
                'status' => 'ok',
                'arguments' => ['city' => 'Aarhus'],
                'result' => 'sunny',
                'exception' => null,
                'duration' => 42.0,
            ])
            ->and($tools[1])->toMatchArray(['tool_invocation_id' => 'tool-2', 'status' => 'failed', 'result' => null, 'duration' => 1.5])
            ->and($tools[1]['exception']['message'])->toBe('no forecast');

        $summary = app(AiLens::class)->summarize($capture);

        expect($summary->totals)->toMatchArray(['ai_tool_ms' => 43.5, 'ai_tool_calls' => 2])
            ->and($summary->labels)->toMatchArray(['ai_tool_ms' => 'in tools', 'ai_tool_calls' => 'tool calls']);
    });

    it('shows tool calls on the AI tab', function (): void {
        expect(app(AiLens::class)->presentation()->includes)->toContain('ai_tools');
    });

    it('records no tool rows outside a capture and no tool totals without tool calls', function (): void {
        event(new PromptingAgent('inv-1', $this->prompt));
        event(new AgentPrompted('inv-1', $this->prompt, new AgentResponse('inv-1', 'done', new TextUsage(1, 1), new Meta('openai', 'gpt-test'))));

        $summary = app(AiLens::class)->summarize(app(Eyepiece::class)->finalize());

        expect($summary->totals)->not->toHaveKey('ai_tool_calls');

        event(new ToolInvoked('inv-9', 'tool-9', $this->agent, $this->tool, [], 'late', 1.0));
        event(new ToolFailed('inv-9', 'tool-9', $this->agent, $this->tool, [], new RuntimeException('late'), 1.0));

        expect(app(Eyepiece::class)->active())->toBeNull();
    });

    it('marks a streamed agent run as streamed', function (): void {
        event(new PromptingAgent('inv-1', $this->prompt));
        event(new AgentPrompted('inv-1', $this->prompt, new StreamedAgentResponse('inv-1', collect(), new Meta('openai', 'gpt-test'))));

        expect(app(Eyepiece::class)->finalize()->rows('ai')[0]['streamed'])->toBeTrue();
    });

    it('keeps a tool result with invalid UTF-8 as JSON text', function (): void {
        event(new PromptingAgent('inv-1', $this->prompt));
        event(new InvokingTool('inv-1', 'tool-1', $this->agent, $this->tool, []));
        event(new ToolInvoked('inv-1', 'tool-1', $this->agent, $this->tool, [], ['bad' => "\xB1\x31"], 1.0));

        expect(app(Eyepiece::class)->finalize()->rows('ai_tools')[0]['result'])->toBeString()->toContain('bad');
    });

    it('counts the time of nested agents and their tools once', function (): void {
        $eyepiece = app(Eyepiece::class);
        $agent = ['agent' => Greeter::class, 'provider' => 'openai', 'model' => 'gpt-test', 'status' => 'ok', 'tokens' => []];

        $eyepiece->push('ai', [...$agent, 'invocation_id' => 'inv-2', 'parent_invocation_id' => 'inv-1', 'duration' => 30.0]);
        $eyepiece->push('ai', [...$agent, 'invocation_id' => 'inv-1', 'parent_invocation_id' => null, 'duration' => 100.0]);
        $eyepiece->push('ai', [...$agent, 'invocation_id' => 'inv-3', 'parent_invocation_id' => 'gone', 'duration' => 5.0]);
        $eyepiece->push('ai_tools', ['tool' => 'researcher', 'invocation_id' => 'inv-1', 'status' => 'ok', 'duration' => 40.0]);
        $eyepiece->push('ai_tools', ['tool' => 'weather', 'invocation_id' => 'inv-2', 'status' => 'ok', 'duration' => 10.0]);

        $summary = app(AiLens::class)->summarize($eyepiece->finalize());

        expect($summary->totals)->toMatchArray(['ai_ms' => 105.0, 'ai_calls' => 3, 'ai_tool_ms' => 40.0, 'ai_tool_calls' => 2]);
    });

    it('masks keyed tool arguments and results and keeps free text', function (): void {
        config(['monocle.mask.inputs' => ['/city/i']]);

        event(new PromptingAgent('inv-1', $this->prompt));
        event(new InvokingTool('inv-1', 'tool-1', $this->agent, $this->tool, ['city' => 'Aarhus', 'days' => 2]));
        event(new ToolInvoked('inv-1', 'tool-1', $this->agent, $this->tool, [], ['city' => 'Aarhus', 'sky' => 'sunny'], 1.0));
        event(new InvokingTool('inv-1', 'tool-2', $this->agent, $this->tool, []));
        event(new ToolInvoked('inv-1', 'tool-2', $this->agent, $this->tool, [], '{"city":"Oslo"}', 1.0));
        event(new InvokingTool('inv-1', 'tool-3', $this->agent, $this->tool, []));
        event(new ToolInvoked('inv-1', 'tool-3', $this->agent, $this->tool, [], 'city: Bergen', 1.0));

        $tools = app(Eyepiece::class)->finalize()->rows('ai_tools');

        expect($tools[0]['arguments'])->toMatchArray(['days' => 2])
            ->and($tools[0]['arguments']['city'])->not->toBe('Aarhus')
            ->and(json_decode($tools[0]['result'], true))->toMatchArray(['sky' => 'sunny'])
            ->and($tools[0]['result'])->not->toContain('Aarhus')
            ->and($tools[1]['result'])->not->toContain('Oslo')
            ->and($tools[2]['result'])->toBe('city: Bergen');
    });

    it('names the agent behind an agent used as a tool', function (): void {
        $subAgent = new AgentTool(new Greeter());

        event(new PromptingAgent('inv-1', $this->prompt));
        event(new InvokingTool('inv-1', 'tool-1', $this->agent, $subAgent, ['task' => 'Say hi']));
        event(new ToolInvoked('inv-1', 'tool-1', $this->agent, $subAgent, ['task' => 'Say hi'], 'Hi!', 12.0));

        $row = app(Eyepiece::class)->finalize()->rows('ai_tools')[0];

        expect($row)->toMatchArray(['tool' => 'Greeter', 'class' => Greeter::class, 'sub_agent' => true, 'result' => 'Hi!']);
    });
});
