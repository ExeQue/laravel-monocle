<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\Views\TimedEngine;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\View\Engines\EngineResolver;
use Throwable;

/**
 * Times every view render by wrapping the view engines, so nested includes
 * and components show up as nested spans with real durations.
 */
final class ViewsLens implements Configurable, Lens, Summarizable
{
    /** @var list<string> */
    private array $engines = ['blade', 'php', 'file'];

    private bool $data = false;

    private int $repeatThreshold = 10;

    public function key(): string
    {
        return 'views';
    }

    public function description(): string
    {
        return 'Rendered views: view name, path, engine, duration and the keys of the data passed in (values only with the option data=true). Renders nest as view spans in the timeline, so an include inside a loop shows its repetition.';
    }

    public function configure(array $options): void
    {
        $this->engines = array_values((array) ($options['engines'] ?? $this->engines));
        $this->data = (bool) ($options['data'] ?? $this->data);
        $this->repeatThreshold = (int) ($options['repeat_threshold'] ?? $this->repeatThreshold);
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

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('views');

        if ($rows === []) {
            return [];
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
        $warnings = [];

        foreach ($repeats as $view => $count) {
            if ($count >= $this->repeatThreshold) {
                $warnings[] = "View {$view} rendered {$count} times.";
            }
        }

        return [
            'totals' => [
                'views_rendered' => count($rows),
                'views_ms' => round($topLevel, 3),
                'views_most_rendered' => array_slice($repeats, 0, 3, true),
            ],
            'warnings' => $warnings,
        ];
    }
}
