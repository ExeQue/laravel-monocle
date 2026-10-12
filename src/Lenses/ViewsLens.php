<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Enums\Unit;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Views\TimedEngine;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\View\Engines\EngineResolver;
use Throwable;

/**
 * Times every view render by wrapping the view engines, so nested includes
 * and components show up as nested spans with real durations.
 */
final class ViewsLens implements Advises, Configurable, Lens, Presentable, Summarizable
{
    /** @var list<string> */
    private array $engines = ['blade', 'php', 'file'];

    private bool $data = false;

    private int $repeatThreshold = 10;

    private float $repeatMs = 20.0;

    public function key(): string
    {
        return 'views';
    }

    public function description(): string
    {
        return 'Rendered views: view name, path, engine, duration and the keys of the data passed in (values only with the option data=true). Renders nest as view spans in the timeline, so an include inside a loop shows its repetition.';
    }

    public function advice(): string
    {
        return 'Look here when rendering is slow: which views and components rendered, how often and how long they took.';
    }

    public function configure(array $options): void
    {
        $this->engines = array_values((array) ($options['engines'] ?? $this->engines));
        $this->data = (bool) ($options['data'] ?? $this->data);
        $this->repeatThreshold = (int) ($options['repeat_threshold'] ?? $this->repeatThreshold);
        $this->repeatMs = (float) ($options['repeat_ms'] ?? $this->repeatMs);
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        // Other packages (Livewire, Volt) re-register the blade engine while
        // booting, so the wrapping waits until every provider has booted.
        $app->booted(fn () => $this->wrapEngines($app, $eyepiece));
    }

    private function wrapEngines(Application $app, Eyepiece $eyepiece): void
    {
        /** @var EngineResolver $resolver */
        $resolver = $app->make('view.engine.resolver');
        $viewPaths = fn (): array => $app->make('view')->getFinder()->getPaths();

        foreach ($this->engines as $engine) {
            try {
                $inner = $resolver->resolve($engine);
            } catch (Throwable) {
                continue;
            }

            if ($inner instanceof TimedEngine) {
                $inner = $inner->inner();
            }

            $timed = new TimedEngine($inner, $engine, $eyepiece, $viewPaths, $app->basePath(), $this->data);

            $resolver->register($engine, fn (): TimedEngine => $timed);
        }
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('views');

        if ($rows === []) {
            return Summary::make();
        }

        // Only views not rendered inside another view count, or nested time would be counted twice.
        $topLevel = collect($capture->spansOfKind('view'))
            ->reject(fn (Span $span): bool => $this->isNested($capture, $span))
            ->sum(fn (Span $span): float => $span->duration() ?? 0.0);

        // Each view with how often it rendered and the time it took, most rendered first.
        $views = collect($rows)
            ->groupBy('view')
            ->map(fn (Collection $renders): array => [
                'count' => $renders->count(),
                'ms' => $renders->sum(fn (array $row): float => (float) ($row['duration'] ?? 0)),
                'library' => str_starts_with((string) ($renders->last()['path'] ?? ''), 'vendor/'),
            ])
            ->sortByDesc('count');

        $warnings = $views
            ->filter(fn (array $view): bool => $view['count'] >= $this->repeatThreshold
                && $view['ms'] >= $this->repeatMs
                // A package's views are not the application's to fix.
                && ! $view['library'])
            ->map(fn (array $view, int|string $name): string => sprintf('View %s rendered %d times, %.1f ms in all.', $name, $view['count'], $view['ms']));

        return Summary::make()
            ->total('views_ms', round($topLevel, 3), 'spent', Unit::Milliseconds)
            ->totals([
                'views_most_rendered' => $views->take(3)->map(fn (array $view): int => $view['count'])->all(),
            ])
            ->total('views_rendered', count($rows), 'rendered')
            ->warnings($warnings);
    }

    private function isNested(Capture $capture, Span $span): bool
    {
        if ($span->parentId === null) {
            return false;
        }

        $parent = $capture->spans[$span->parentId] ?? null;

        return $parent instanceof Span && $parent->kind === 'view';
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.views')
            ->groupBy(['view' => 'by view', 'engine' => 'by engine']);
    }
}
