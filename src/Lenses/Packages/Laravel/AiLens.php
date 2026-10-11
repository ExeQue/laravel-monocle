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
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Tools\AgentTool;
use Stringable;
use Throwable;

/**
 * laravel/ai agent runs as a span tree: agent -> step -> tool, with model,
 * tokens, prompt and response, nested agents linked through their parent
 * invocation.
 */
final class AiLens implements Advises, Configurable, Lens, Presentable, RequiresPackages, Resettable, Summarizable
{
    /** @var array{input: int, output: int, cache_read: int, cache_write: int, reasoning: int} */
    private const array NO_TOKENS = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0];

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
        return 'laravel/ai agent invocations: agent class, provider, model, prompt, response text, steps, tool calls, tokens (input, output, cache, reasoning), duration and status. Each invocation is an ai.agent span with ai.step and ai.tool children in the timeline; nested agents hang under the tool that invoked them. Section ai_tools has one row per tool call: tool, class, sub_agent (true when the tool is another agent; class is then that agent), agent, status, arguments, result (or exception) and duration. The time totals count only top-level runs and their tool calls, as a sub-agent runs inside the tool call that started it.';
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

        $tools = $capture->rows('ai_tools');
        $topLevel = $this->topLevelInvocations($rows);
        $ms = 0.0;
        $toolMs = 0.0;

        // A nested agent and its tools run inside the tool call of the agent above, so time counts only once: at the top.
        foreach ($rows as $row) {
            if (in_array($row['invocation_id'] ?? null, $topLevel, true)) {
                $ms += (float) ($row['duration'] ?? 0);
            }
        }

        foreach ($tools as $tool) {
            if (in_array($tool['invocation_id'] ?? null, $topLevel, true)) {
                $toolMs += (float) ($tool['duration'] ?? 0);
            }
        }

        $tokens = self::NO_TOKENS;
        $byModel = [];
        $warnings = [];

        foreach ($rows as $row) {
            foreach ($tokens as $key => $value) {
                $tokens[$key] = $value + (int) ($row['tokens'][$key] ?? 0);
            }

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
            ->when($tools !== [])
            ->total('ai_tool_ms', round($toolMs, 3), 'in tools', Unit::Milliseconds)
            ->when($tools !== [])
            ->total('ai_tool_calls', count($tools), 'tool calls')
            ->errors(array_slice($warnings, 0, 5));
    }

    /**
     * The invocations not prompted from inside another recorded invocation.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<mixed>
     */
    private function topLevelInvocations(array $rows): array
    {
        $invocations = array_column($rows, 'invocation_id');
        $topLevel = array_filter($rows, fn (array $row): bool => ! in_array($row['parent_invocation_id'] ?? null, $invocations, true));

        return array_column($topLevel, 'invocation_id');
    }

    private function onPrompting(PromptingAgent $event): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $prompt = $event->prompt;
        $parent = $this->parentSpan($prompt);

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
        $this->usage[$event->invocationId] = self::NO_TOKENS;
    }

    /**
     * A nested agent hangs under the tool call that invoked it, or else
     * under the agent it was prompted from.
     */
    private function parentSpan(AgentPrompt $prompt): ?Span
    {
        $tool = $this->tools[$prompt->parentToolInvocationId ?? ''] ?? null;

        if ($tool !== null) {
            return $tool;
        }

        return $this->agents[$prompt->parentInvocationId ?? ''] ?? null;
    }

    private function onPrompted(AgentPrompted $event): void
    {
        $span = $this->agents[$event->invocationId] ?? null;

        if ($span === null) {
            return;
        }

        $response = $event->response;

        // A streamed run ends with a StreamedAgentResponse (an AgentResponse too) built from its events.
        $isComplete = $response instanceof AgentResponse;
        $isStreamed = $response instanceof StreamedAgentResponse;
        $text = $isComplete ? $response->text : null;
        $hasStructured = $isComplete && property_exists($response, 'structured');
        $structured = $hasStructured ? Normalizer::value($response->structured ?? null) : null;

        if ($isComplete) {
            $this->addUsage($event->invocationId, $response->usage);
        }

        $this->finishAgent($event->invocationId, 'ok', [
            'response' => $this->text($text),
            'structured' => $structured,
            'streamed' => $isStreamed,
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
            'arguments' => $this->eyepiece->masker(self::class)->inputs(Normalizer::normalize($event->arguments)),
        ]);

        $this->tools[$event->toolInvocationId] = $span;
        $agent->meta(['tool_calls' => ($agent->meta['tool_calls'] ?? 0) + 1]);
    }

    private function onToolInvoked(ToolInvoked $event): void
    {
        $result = $this->text($this->maskedResult($event->result));

        ($this->tools[$event->toolInvocationId] ?? null)?->stop([
            'result' => $result,
            'status' => 'ok',
            'tool_ms' => round($event->time, 3),
        ]);

        $this->recordTool($event->invocationId, $event->toolInvocationId, $event->tool, 'ok', $event->time, result: $result);
    }

    private function onToolFailed(ToolFailed $event): void
    {
        $exception = ThrowableChain::describe($event->exception);

        ($this->tools[$event->toolInvocationId] ?? null)?->stop([
            'status' => 'failed',
            'exception' => $exception,
            'tool_ms' => round($event->time, 3),
        ]);

        $this->recordTool($event->invocationId, $event->toolInvocationId, $event->tool, 'failed', $event->time, exception: $exception);

        $agent = $this->agents[$event->invocationId] ?? null;

        if ($agent === null) {
            return;
        }

        $failedTools = [...($agent->meta['failed_tools'] ?? []), $this->toolName($event->tool)];

        $agent->meta(['failed_tools' => $failedTools]);
    }

    /**
     * One row per tool call, so the calls and their time can be listed and
     * sorted next to the agents that made them.
     *
     * @param  array<string, mixed>|null  $exception
     */
    private function recordTool(string $invocationId, string $toolInvocationId, object $tool, string $status, float $ms, ?string $result = null, ?array $exception = null): void
    {
        $span = $this->tools[$toolInvocationId] ?? null;
        unset($this->tools[$toolInvocationId]);

        if (! $this->eyepiece->recording()) {
            return;
        }

        $agent = $this->agents[$invocationId] ?? null;
        // An agent offered as a tool runs as a sub-agent; name the agent rather than the AgentTool wrapper.
        $subAgent = $tool instanceof AgentTool ? $tool->agent()::class : null;

        $this->eyepiece->push('ai_tools', [
            'tool' => $this->toolName($tool),
            'class' => $subAgent ?? $tool::class,
            'sub_agent' => $subAgent !== null,
            'agent' => $agent?->meta['agent'] ?? null,
            'invocation_id' => $invocationId,
            'tool_invocation_id' => $toolInvocationId,
            'status' => $status,
            'arguments' => $span?->meta['arguments'] ?? null,
            'result' => $result,
            'exception' => $exception,
            'duration' => round($ms, 3),
        ]);
    }

    /**
     * A result with keys (an array, an object or JSON text) is masked like
     * input; free text stays as it is.
     */
    private function maskedResult(mixed $result): ?string
    {
        $text = $this->resultText($result);

        return $text === null ? null : $this->eyepiece->masker(self::class)->body($text);
    }

    /**
     * Turning the result into text can run the application's own
     * __toString or JsonSerializable code, so it is not Monocle's time.
     */
    private function resultText(mixed $result): ?string
    {
        return $this->eyepiece->outside(function () use ($result) {
            if (is_scalar($result) || $result instanceof Stringable) {
                return (string) $result;
            }

            // Invalid UTF-8 in a tool result must not turn the whole result into false.
            $json = json_encode(Normalizer::value($result), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

            return $json === false ? null : $json;
        });
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
        // Newest first: the last open step of this invocation is the one running.
        foreach (array_reverse($this->steps, true) as $key => $span) {
            if (! str_starts_with($key, $invocationId.':')) {
                continue;
            }

            if ($span->isOpen()) {
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
        if ($text === null) {
            // Unreachable: every laravel/ai response carries a string text and tool results are always encoded to one.
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        if ($this->maxText === null) {
            return $text;
        }

        if (mb_strlen($text) <= $this->maxText) {
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
        return Presentation::table()
            ->tab('monocle::tabs.ai')
            ->format(['status' => 'status'])
            ->includes([
                'ai_tools' => Presentation::table()
                    ->label('Tool calls')
                    ->columns(['tool', 'sub_agent' => 'Sub-agent', 'agent', 'status', 'duration'])
                    ->detailsOnly(['arguments', 'result', 'exception'])
                    ->format(['tool' => 'code', 'sub_agent' => ['flag', 'agent', null, 'muted', 'muted'], 'status' => 'status'])
                    ->groupBy(['tool' => 'by tool', 'agent' => 'by agent']),
            ]);
    }
}
