<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Prism;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\ProvidesSections;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;
use Prism\Prism\PrismManager;

/**
 * prism-php/prism calls: text, structured, stream, embeddings, images,
 * moderation and audio. Prism fires no events, so the Prism manager is
 * replaced with one that wraps every provider it resolves.
 */
final class PrismLens implements Advises, Configurable, Lens, Presentable, ProvidesSections, RequiresPackages, Summarizable
{
    private ?int $maxText = null;

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'prism';
    }

    public function sections(): array
    {
        return ['prism_tools'];
    }

    public static function packages(): array
    {
        return ['prism-php/prism'];
    }

    public function description(): string
    {
        return 'prism-php/prism calls: operation (text, structured, stream, embeddings, images, moderation, text_to_speech, speech_to_text), provider, model, prompt, response text, structured output, finish reason, steps, tool calls, tools offered, tokens (input, output, cache, reasoning), duration and status (ok, failed, abandoned for a stream not read to the end). Each call is a prism.call span in the timeline; streams get prism.step children and tool calls prism.tool children. Section prism_tools has one row per tool call: tool, status, arguments, result (or exception) and duration. Rows in both carry nested: true for a call made while another Prism call ran (from one of its tools) and for the tools such a call ran; the time totals leave nested rows out, as their time is already part of the call around them.';
    }

    public function advice(): string
    {
        return 'Look here when a Prism call is slow, costly or answers wrongly: the prompt, the response, tool calls and tokens.';
    }

    public function configure(array $options): void
    {
        $this->maxText = isset($options['max_text']) ? (int) $options['max_text'] : null;
    }

    /**
     * Prism::fake() swaps the manager in with instance(), which skips
     * extenders, so the swapped-in manager is wrapped on rebinding too.
     */
    public function boot(Application $app): void
    {
        $wrap = fn (PrismManager $manager, Application $app): TracedPrismManager => new TracedPrismManager(
            inner: $manager instanceof TracedPrismManager ? $manager->inner() : $manager,
            eyepiece: $this->eyepiece,
            maxText: $this->maxText,
            app: $app,
        );

        $app->extend(PrismManager::class, $wrap);

        $app->rebinding(PrismManager::class, function (Application $app, PrismManager $manager) use ($wrap): void {
            if ($manager instanceof TracedPrismManager) {
                return;
            }

            $app->instance(PrismManager::class, $wrap($manager, $app));
        });
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('prism');

        if ($rows === []) {
            return Summary::make();
        }

        $tools = $capture->rows('prism_tools');
        $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0];
        $ms = 0.0;
        $byModel = [];
        $tokensByModel = [];
        $errors = [];

        foreach ($rows as $row) {
            foreach ($tokens as $key => $value) {
                $tokens[$key] = $value + (int) ($row['tokens'][$key] ?? 0);
            }

            // A call made from inside another call (from one of its tools) runs in that call's time.
            $ms += ($row['nested'] ?? false) ? 0.0 : (float) ($row['duration'] ?? 0);
            $model = ($row['provider'] ?? '?').'/'.($row['model'] ?? '?');
            $byModel[$model] = ($byModel[$model] ?? 0) + 1;

            // Prism leaves the input read from cache out of the input, so each is priced as it is.
            $tokensByModel[$model] ??= ['input' => 0, 'cached' => 0, 'output' => 0];
            $tokensByModel[$model]['input'] += (int) ($row['tokens']['input'] ?? 0);
            $tokensByModel[$model]['cached'] += (int) ($row['tokens']['cache_read'] ?? 0);
            $tokensByModel[$model]['output'] += (int) ($row['tokens']['output'] ?? 0);

            if ($row['status'] === 'failed') {
                $errors[] = sprintf('Prism %s on %s failed: %s', $row['operation'], $model, $row['exception']['message'] ?? '');
            }

            // Timed tool calls report their own failures below; a stream also names them.
            if ($tools !== []) {
                continue;
            }

            foreach ($row['failed_tools'] ?? [] as $tool) {
                $errors[] = sprintf('Tool %s failed during Prism %s on %s.', $tool, $row['operation'], $model);
            }
        }

        $toolMs = 0.0;

        foreach ($tools as $tool) {
            // So does a tool called by such a nested call.
            $toolMs += ($tool['nested'] ?? false) ? 0.0 : (float) ($tool['duration'] ?? 0);

            if ($tool['status'] === 'failed') {
                $errors[] = sprintf('Prism tool %s failed: %s', $tool['tool'], $tool['exception']['message'] ?? '');
            }
        }

        return Summary::make()
            ->total('prism_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->totals([
                'prism_tokens' => $tokens,
                'prism_by_model' => $byModel,
                'prism_tokens_by_model' => $tokensByModel,
            ])
            ->total('prism_input_tokens', $tokens['input'], 'input', 'tokens')
            ->total('prism_output_tokens', $tokens['output'], 'output', 'tokens')
            ->total('prism_cached_tokens', $tokens['cache_read'], 'cached', 'tokens')
            ->total('prism_calls', count($rows), 'calls')
            ->when($tools !== [])
            ->total('prism_tool_ms', round($toolMs, 3), 'in tools', Unit::Milliseconds)
            ->when($tools !== [])
            ->total('prism_tool_calls', count($tools), 'tool calls')
            ->errors(array_slice($errors, 0, 5));
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.prism')
            ->details('monocle::details.prism')
            ->format(['status' => 'status'])
            ->includes([
                'prism_tools' => Presentation::table()
                    ->label('Tool calls')
                    ->columns(['tool', 'status', 'duration'])
                    ->detailsOnly(['arguments', 'result', 'exception'])
                    ->details('monocle::details.prism-tool')
                    ->format(['tool' => 'code', 'status' => 'status'])
                    ->groupBy(['tool' => 'by tool']),
            ]);
    }
}
