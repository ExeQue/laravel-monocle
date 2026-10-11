<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Prism;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
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
final class PrismLens implements Advises, Configurable, Lens, Presentable, RequiresPackages, Summarizable
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

    public static function packages(): array
    {
        return ['prism-php/prism'];
    }

    public function description(): string
    {
        return 'prism-php/prism calls: operation (text, structured, stream, embeddings, images, moderation, text_to_speech, speech_to_text), provider, model, prompt, response text, structured output, finish reason, steps, tool calls, tools offered, tokens (input, output, cache, reasoning), duration and status (ok, failed, abandoned for a stream not read to the end). Each call is a prism.call span in the timeline; streams get prism.step children.';
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
        if (! class_exists(PrismManager::class)) {
            return;
        }

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

        $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0];
        $ms = 0.0;
        $byModel = [];
        $errors = [];

        foreach ($rows as $row) {
            foreach ($tokens as $key => $value) {
                $tokens[$key] = $value + (int) ($row['tokens'][$key] ?? 0);
            }

            $ms += (float) ($row['duration'] ?? 0);
            $model = ($row['provider'] ?? '?').'/'.($row['model'] ?? '?');
            $byModel[$model] = ($byModel[$model] ?? 0) + 1;

            if ($row['status'] === 'failed') {
                $errors[] = sprintf('Prism %s on %s failed: %s', $row['operation'], $model, $row['exception']['message'] ?? '');
            }

            foreach ($row['failed_tools'] ?? [] as $tool) {
                $errors[] = sprintf('Tool %s failed during Prism %s on %s.', $tool, $row['operation'], $model);
            }
        }

        return Summary::make()
            ->total('prism_ms', round($ms, 3), 'spent', Unit::Milliseconds)
            ->totals([
                'prism_tokens' => $tokens,
                'prism_by_model' => $byModel,
            ])
            ->total('prism_calls', count($rows), 'calls')
            ->errors(array_slice($errors, 0, 5));
    }

    public function presentation(): Presentation
    {
        return Presentation::table()->tab('monocle::tabs.prism')->format(['status' => 'status']);
    }
}
