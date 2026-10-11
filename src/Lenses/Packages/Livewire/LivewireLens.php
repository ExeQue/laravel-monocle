<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Livewire;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;

/**
 * Livewire component lifecycle: which components mounted or hydrated, the
 * methods called on them, property updates, render time and exceptions. A
 * Livewire request otherwise looks like a bare `POST /livewire/update`.
 */
final class LivewireLens implements Advises, Finalizable, Lens, Resettable, Summarizable
{
    /** @var array<string, array<string, mixed>> */
    private array $components = [];

    /** @var array<string, Span> */
    private array $spans = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

    public function key(): string
    {
        return 'livewire';
    }

    public function description(): string
    {
        return 'Livewire components touched by the request: component name and class, lifecycle (mount or update), the methods called with their params and duration, property updates, render_ms, html_bytes and any exception. Each component is also a livewire span in the timeline.';
    }

    public function advice(): string
    {
        return 'Look here for Livewire components: which methods were called, which properties changed and how long rendering took.';
    }

    public function boot(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        $this->hook('mount', function (Component $component): void {
            $this->open($component, 'mount');
        });

        $this->hook('hydrate', function (Component $component): void {
            $this->open($component, 'update');
        });

        $this->hook('call', function (Component $component, string $method, array $params): ?callable {
            if (! $this->track($component)) {
                return null;
            }

            $started = $this->eyepiece->now();

            return function () use ($component, $method, $params, $started): void {
                $this->components[$component->getId()]['calls'][] = [
                    'method' => $method,
                    'params' => Normalizer::normalize($params),
                    'duration' => round($this->eyepiece->now() - $started, 3),
                ];
            };
        });

        $this->hook('update', function (Component $component, string $path, mixed $value): void {
            if ($this->track($component)) {
                $this->components[$component->getId()]['updates'][] = ['path' => $path, 'value' => Normalizer::value($value)];
            }
        });

        $this->hook('render', function (Component $component): ?callable {
            if (! $this->track($component)) {
                return null;
            }

            $started = $this->eyepiece->now();

            return function (mixed $html) use ($component, $started): void {
                $this->components[$component->getId()]['render_ms'] = round($this->eyepiece->now() - $started, 3);
                $this->components[$component->getId()]['html_bytes'] = is_string($html) ? strlen($html) : null;
            };
        });

        $this->hook('dehydrate', function (Component $component): void {
            $this->close($component);
        });

        $this->hook('exception', function (mixed $target, Throwable $exception): void {
            if ($target instanceof Component && $this->track($target)) {
                $this->components[$target->getId()]['exception'] = ['class' => $exception::class, 'message' => $exception->getMessage()];
            }
        });
    }

    public function finalize(Capture $capture): void
    {
        foreach ($this->components as $id => $component) {
            $span = $this->spans[$id] ?? null;
            $span?->stop();

            $this->eyepiece->push('livewire', [...$component, 'duration' => $span?->duration()], origin: false);
        }

        $this->components = [];
    }

    public function reset(): void
    {
        $this->components = [];
        $this->spans = [];
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('livewire');

        if ($rows === []) {
            return [];
        }

        $calls = 0;
        $warnings = [];

        foreach ($rows as $row) {
            $calls += count($row['calls'] ?? []);

            if (isset($row['exception'])) {
                $warnings[] = sprintf('Livewire %s threw %s: %s', $row['component'], $row['exception']['class'], $row['exception']['message']);
            }
        }

        return [
            'totals' => ['livewire_components' => count($rows), 'livewire_calls' => $calls],
            'warnings' => $warnings,
        ];
    }

    private function hook(string $event, callable $callback): void
    {
        app('livewire')->listen($event, function (mixed ...$arguments) use ($callback): mixed {
            try {
                return $callback(...$arguments);
            } catch (Throwable $e) {
                return null;
            }
        });
    }

    private function open(Component $component, string $lifecycle): void
    {
        if (! $this->eyepiece->recording()) {
            return;
        }

        $id = $component->getId();

        $this->components[$id] ??= [
            'component' => $component->getName(),
            'class' => $component::class,
            'id' => $id,
            'lifecycle' => $lifecycle,
            'time' => $this->eyepiece->now(),
            'calls' => [],
            'updates' => [],
            'render_ms' => null,
            'html_bytes' => null,
            'exception' => null,
        ];

        $this->spans[$id] ??= $this->eyepiece->span($component->getName(), 'livewire')->start(['class' => $component::class, 'lifecycle' => $lifecycle]);
    }

    private function close(Component $component): void
    {
        $id = $component->getId();

        if (isset($this->spans[$id])) {
            $this->spans[$id]->stop();
        }
    }

    private function track(Component $component): bool
    {
        return isset($this->components[$component->getId()]);
    }
}
