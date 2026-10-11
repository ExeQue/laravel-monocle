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
});
