<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Laravel;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use ExeQue\Monocle\Support\ThrowableChain;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\TextUsage;
use Stringable;
use Throwable;

/**
 * laravel/ai agent runs as a span tree: agent -> step -> tool, with model,
 * tokens, prompt and response, nested agents linked through their parent
 * invocation.
 */
final class AiLens implements Advises, Configurable, Lens, Presentable, RequiresPackages, Resettable, Summarizable
{
    private ?int $maxText = null;

    /** @var array<string, Span> */
    private array $agents = [];

    /** @var array<string, Span> */
    private array $steps = [];

    /** @var array<string, Span> */
    private array $tools = [];

    /** @var array<string, array<string, int>> */
    private array $usage = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'ai';
    }

    public static function packages(): array
    {
        return ['laravel/ai'];
    }

    public function description(): string
    {
        return 'laravel/ai agent invocations: agent class, provider, model, prompt, response text, steps, tool calls, tokens (input, output, cache, reasoning), duration and status. Each invocation is an ai.agent span with ai.step and ai.tool children in the timeline; nested agents hang under the tool that invoked them.';
    }

    public function advice(): string
    {
        return 'Look here when an AI agent is slow, costly or answers wrongly: the prompt, each step, tool calls and tokens.';
    }

    public function configure(array $options): void
    {
        $this->maxText = isset($options['max_text']) ? (int) $options['max_text'] : null;
    }

    public function boot(): void
    {
        if (! class_exists(PromptingAgent::class)) {
            return;
        }

        $this->eyepiece->listen(PromptingAgent::class, $this->onPrompting(...));
        $this->eyepiece->listen(AgentPrompted::class, $this->onPrompted(...));
        $this->eyepiece->listen(AgentFailed::class, $this->onFailed(...));
        $this->eyepiece->listen(StartingStep::class, $this->onStartingStep(...));
        $this->eyepiece->listen(StepCompleted::class, $this->onStepCompleted(...));
        $this->eyepiece->listen(StepFailed::class, $this->onStepFailed(...));
        $this->eyepiece->listen(InvokingTool::class, $this->onInvokingTool(...));
        $this->eyepiece->listen(ToolInvoked::class, $this->onToolInvoked(...));
        $this->eyepiece->listen(ToolFailed::class, $this->onToolFailed(...));
    }

    public function reset(): void
    {
        $this->agents = [];
        $this->steps = [];
        $this->tools = [];
        $this->usage = [];
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('ai');

        if ($rows === []) {
            return Summary::make();
        }

        $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0];
        $ms = 0.0;
        $byModel = [];
        $warnings = [];

        foreach ($rows as $row) {
            foreach ($tokens as $key => $value) {
                $tokens[$key] = $value + (int) ($row['tokens'][$key] ?? 0);
            }

            $ms += (float) ($row['duration'] ?? 0);
            $model = ($row['provider'] ?? '?').'/'.($row['model'] ?? '?');
            $byModel[$model] = ($byModel[$model] ?? 0) + 1;

            if ($row['status'] !== 'ok') {
                $warnings[] = sprintf('Agent %s %s: %s', $row['agent'], $row['status'], $row['exception']['message'] ?? '');
            }

            foreach ($row['failed_tools'] ?? [] as $tool) {
                $warnings[] = sprintf('Tool %s failed during %s.', $tool, $row['agent']);
            }
        }

        return Summary::make()
            ->total('ai_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->totals([
                'ai_tokens' => $tokens,
                'ai_by_model' => $byModel,
            ])
            ->total('ai_calls', count($rows), 'calls')
            ->errors(array_slice($warnings, 0, 5));
    }

    private function onPrompting(PromptingAgent $event): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $prompt = $event->prompt;
        $parent = $this->tools[$prompt->parentToolInvocationId ?? ''] ?? $this->agents[$prompt->parentInvocationId ?? ''] ?? null;

        $span = $this->eyepiece->span($this->agentName($prompt->agent), 'ai.agent');

        if ($parent !== null) {
            $span->parent($parent);
        }

        $span->start([
            'agent' => $prompt->agent::class,
            'invocation_id' => $event->invocationId,
            'parent_invocation_id' => $prompt->parentInvocationId,
            'provider' => $this->providerName($prompt),
            'model' => $prompt->model,
            'prompt' => $this->text($prompt->prompt),
            'attachments' => $prompt->attachments->count(),
            'steps' => 0,
            'tool_calls' => 0,
            'failed_tools' => [],
        ]);

        $this->agents[$event->invocationId] = $span;
        $this->usage[$event->invocationId] = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0];
    }

    private function onPrompted(AgentPrompted $event): void
    {
        $span = $this->agents[$event->invocationId] ?? null;

        if ($span === null) {
            return;
        }

        $response = $event->response;
        $text = $response instanceof AgentResponse ? $response->text : null;

        if ($response instanceof AgentResponse) {
            $this->addUsage($event->invocationId, $response->usage);
        }

        $this->finishAgent($event->invocationId, 'ok', [
            'response' => $this->text($text),
            'structured' => $response instanceof AgentResponse && property_exists($response, 'structured') ? Normalizer::value($response->structured ?? null) : null,
            'streamed' => ! $response instanceof AgentResponse,
            'provider' => $response->meta->provider ?? $span->meta['provider'] ?? null,
            'model' => $response->meta->model ?? $span->meta['model'] ?? null,
        ]);
    }

    private function onFailed(AgentFailed $event): void
    {
        if (! isset($this->agents[$event->invocationId])) {
            return;
        }

        $this->finishAgent($event->invocationId, 'failed', [
            'exception' => ThrowableChain::describe($event->exception),
        ]);
    }

    private function onStartingStep(StartingStep $event): void
    {
        $agent = $this->agents[$event->invocationId] ?? null;

        if ($agent === null) {
            return;
        }

        $span = $this->eyepiece->span('step '.$event->stepNumber, 'ai.step');
        $span->parent($agent)->start([
            'step' => $event->stepNumber,
            'provider' => $this->safeName($event->provider),
            'model' => $event->model,
            'messages' => count($event->messages),
            'final' => $event->isFinalStep,
        ]);

        $this->steps[$event->invocationId.':'.$event->stepNumber] = $span;
        $agent->meta(['steps' => ($agent->meta['steps'] ?? 0) + 1]);
    }

    private function onStepCompleted(StepCompleted $event): void
    {
        $this->addUsage($event->invocationId, $event->response->usage);

        $this->finishStep($event->invocationId, $event->stepNumber, [
            'tool_calls' => count($event->response->toolCalls),
            'finish_reason' => Normalizer::value($event->response->finishReason),
            'tokens' => $event->response->usage->toArray(),
            'provider_ms' => round($event->time, 3),
            'status' => 'ok',
        ]);
    }

    private function onStepFailed(StepFailed $event): void
    {
        $this->finishStep($event->invocationId, $event->stepNumber, [
            'status' => 'failed',
            'exception' => ThrowableChain::describe($event->exception),
            'provider_ms' => round($event->time, 3),
        ]);
    }

    private function onInvokingTool(InvokingTool $event): void
    {
        $agent = $this->agents[$event->invocationId] ?? null;

        if ($agent === null) {
            return;
        }

        $span = $this->eyepiece->span($this->toolName($event->tool), 'ai.tool');
        $span->parent($this->currentStep($event->invocationId) ?? $agent)->start([
            'tool' => $event->tool::class,
            'tool_invocation_id' => $event->toolInvocationId,
            'arguments' => Normalizer::normalize($event->arguments),
        ]);

        $this->tools[$event->toolInvocationId] = $span;
        $agent->meta(['tool_calls' => ($agent->meta['tool_calls'] ?? 0) + 1]);
    }

    private function onToolInvoked(ToolInvoked $event): void
    {
        $this->tools[$event->toolInvocationId]?->stop([
            'result' => $this->text($this->eyepiece->outside(fn () => is_scalar($event->result) || $event->result instanceof Stringable ? (string) $event->result : json_encode(Normalizer::value($event->result)))),
            'status' => 'ok',
            'tool_ms' => round($event->time, 3),
        ]);
    }

    private function onToolFailed(ToolFailed $event): void
    {
        $this->tools[$event->toolInvocationId]?->stop([
            'status' => 'failed',
            'exception' => ThrowableChain::describe($event->exception),
            'tool_ms' => round($event->time, 3),
        ]);

        $agent = $this->agents[$event->invocationId] ?? null;
        $agent?->meta(['failed_tools' => [...($agent->meta['failed_tools'] ?? []), $this->toolName($event->tool)]]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function finishAgent(string $invocationId, string $status, array $meta): void
    {
        $span = $this->agents[$invocationId];
        $span->stop([...$meta, 'status' => $status, 'tokens' => $this->usage[$invocationId] ?? []]);

        $this->eyepiece->push('ai', [
            'agent' => $span->meta['agent'],
            'invocation_id' => $invocationId,
            'parent_invocation_id' => $span->meta['parent_invocation_id'],
            'provider' => $span->meta['provider'] ?? null,
            'model' => $span->meta['model'] ?? null,
            'status' => $status,
            'prompt' => $span->meta['prompt'],
            'response' => $span->meta['response'] ?? null,
            'structured' => $span->meta['structured'] ?? null,
            'streamed' => $span->meta['streamed'] ?? false,
            'steps' => $span->meta['steps'],
            'tool_calls' => $span->meta['tool_calls'],
            'failed_tools' => $span->meta['failed_tools'],
            'tokens' => $this->usage[$invocationId] ?? [],
            'exception' => $span->meta['exception'] ?? null,
            'duration' => $span->duration(),
        ], origin: false);

        unset($this->agents[$invocationId], $this->usage[$invocationId]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function finishStep(string $invocationId, int $step, array $meta): void
    {
        $key = $invocationId.':'.$step;

        $this->steps[$key]?->stop($meta);

        unset($this->steps[$key]);
    }

    private function currentStep(string $invocationId): ?Span
    {
        foreach (array_reverse($this->steps, true) as $key => $span) {
            if (str_starts_with($key, $invocationId.':') && $span->isOpen()) {
                return $span;
            }
        }

        return null;
    }

    private function addUsage(string $invocationId, TextUsage $usage): void
    {
        if (! isset($this->usage[$invocationId])) {
            return;
        }

        $current = &$this->usage[$invocationId];
        $current['input'] += $usage->inputTokens;
        $current['output'] += $usage->outputTokens;
        $current['cache_read'] += (int) $usage->cacheReadInputTokens;
        $current['cache_write'] += (int) $usage->cacheWriteInputTokens;
        $current['reasoning'] += (int) $usage->reasoningTokens;
    }

    private function text(?string $text): ?string
    {
        if ($text === null || $this->maxText === null || mb_strlen($text) <= $this->maxText) {
            return $text;
        }

        return mb_substr($text, 0, $this->maxText).'...';
    }

    private function agentName(object $agent): string
    {
        return Str::afterLast($agent::class, '\\');
    }

    private function toolName(object $tool): string
    {
        if (method_exists($tool, 'name')) {
            try {
                return (string) $tool->name();
            } catch (Throwable) {
                // Fall through to the class name.
            }
        }

        return Str::snake(Str::afterLast($tool::class, '\\'));
    }

    private function providerName(object $prompt): ?string
    {
        try {
            return $this->safeName($prompt->provider());
        } catch (Throwable) {
            return null;
        }
    }

    private function safeName(object $provider): ?string
    {
        try {
            return method_exists($provider, 'name') ? (string) $provider->name() : $provider::class;
        } catch (Throwable) {
            return $provider::class;
        }
    }

    public function presentation(): Presentation
    {
        return Presentation::table()->tab('monocle::tabs.ai')->format(['status' => 'status']);
    }
}
