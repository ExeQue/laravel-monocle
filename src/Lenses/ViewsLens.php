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
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Views\TimedEngine;
use Illuminate\Contracts\Foundation\Application;
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

        $topLevel = 0.0;

        foreach ($capture->spansOfKind('view') as $span) {
            $parent = $span->parentId !== null ? ($capture->spans[$span->parentId] ?? null) : null;

            if (! $parent instanceof Span || $parent->kind !== 'view') {
                $topLevel += $span->duration() ?? 0.0;
            }
        }

        $repeats = array_count_values(array_column($rows, 'view'));
        arsort($repeats);
        $spent = [];

        $library = [];

        foreach ($rows as $row) {
            $spent[$row['view']] = ($spent[$row['view']] ?? 0.0) + (float) ($row['duration'] ?? 0);
            $library[$row['view']] = str_starts_with((string) ($row['path'] ?? ''), 'vendor/');
        }

        $warnings = [];

        foreach ($repeats as $view => $count) {
            if ($count >= $this->repeatThreshold && $spent[$view] >= $this->repeatMs && ! $library[$view]) {
                $warnings[] = sprintf('View %s rendered %d times, %.1f ms in all.', $view, $count, $spent[$view]);
            }
        }

        return Summary::make()
            ->total([
                'views_rendered' => count($rows),
                'views_ms' => round($topLevel, 3),
                'views_most_rendered' => array_slice($repeats, 0, 3, true),
            ])
            ->warning($warnings);
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.views')
            ->groupBy(['view' => 'by view', 'engine' => 'by engine']);
    }
}
