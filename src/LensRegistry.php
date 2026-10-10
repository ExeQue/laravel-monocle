<?php

declare(strict_types=1);

namespace ExeQue\Monocle;

use ExeQue\Monocle\Contracts\Configurable;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\SummarizesEntry;
use ExeQue\Monocle\Data\Entry;
use ExeQue\Monocle\Exceptions\LensAlreadyBootedException;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the effective lens list (config merged with runtime registrations)
 * and drives boot/finalize/reset across all enabled lenses.
 */
final class LensRegistry
{
    /** @var array<class-string<Lens>, array<string, mixed>|bool> */
    private array $overrides = [];

    /** @var list<Lens> */
    private array $booted = [];

    private bool $isBooted = false;

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @param  class-string<Lens>  $lens
     * @param  array<string, mixed>|bool  $options
     */
    public function register(string $lens, array|bool $options = true): void
    {
        $this->guardNotBooted($lens);

        $this->overrides[$lens] = $options;
    }

    /**
     * @param  class-string<Lens>  $lens
     */
    public function disable(string $lens): void
    {
        $this->guardNotBooted($lens);

        $this->overrides[$lens] = false;
    }

    /**
     * @param  class-string<Lens>  $lens
     */
    public function enable(string $lens): void
    {
        $this->guardNotBooted($lens);

        $configured = $this->configured()[$lens] ?? true;

        $this->overrides[$lens] = $configured === false ? true : $configured;
    }

    /**
     * The effective lens map: config first, runtime overrides win.
     *
     * @return array<class-string<Lens>, array<string, mixed>|bool>
     */
    public function lenses(): array
    {
        return array_merge($this->configured(), $this->overrides);
    }

    /**
     * @return list<class-string<Lens>>
     */
    public function enabled(): array
    {
        return array_keys(array_filter($this->lenses(), fn (array|bool $options): bool => $options !== false));
    }

    public function boot(Recorder $recorder): void
    {
        if ($this->isBooted) {
            return;
        }

        $this->isBooted = true;

        foreach ($this->lenses() as $class => $options) {
            if ($options === false) {
                continue;
            }

            /** @var Lens $lens */
            $lens = $this->app->make($class);

            if ($lens instanceof Configurable && is_array($options)) {
                $lens->configure($options);
            }

            $lens->boot($this->app, $recorder);

            $this->booted[] = $lens;
        }
    }

    public function isBooted(): bool
    {
        return $this->isBooted;
    }

    /**
     * @return list<Lens>
     */
    public function booted(): array
    {
        return $this->booted;
    }

    /**
     * Section key => description for every enabled lens, whether or not the
     * lenses have been booted in this process (the MCP server reads entries
     * even when recording is off).
     *
     * @return array<string, string>
     */
    public function descriptions(): array
    {
        $descriptions = [];
        $lenses = $this->booted !== [] ? $this->booted : array_map($this->app->make(...), $this->enabled());

        foreach ($lenses as $lens) {
            $descriptions[$lens->key()] = $lens->description();
        }

        return $descriptions;
    }

    /**
     * @return list<SummarizesEntry>
     */
    public function summarizers(): array
    {
        return array_values(array_filter($this->booted, fn (Lens $lens): bool => $lens instanceof SummarizesEntry));
    }

    public function finalize(Entry $entry): void
    {
        foreach ($this->booted as $lens) {
            $lens->finalize($entry);
        }
    }

    public function reset(): void
    {
        foreach ($this->booted as $lens) {
            $lens->reset();
        }
    }

    /**
     * @return array<class-string<Lens>, array<string, mixed>|bool>
     */
    private function configured(): array
    {
        return (array) $this->app->make('config')->get('monocle.lenses', []);
    }

    private function guardNotBooted(string $lens): void
    {
        if ($this->isBooted) {
            throw LensAlreadyBootedException::forRegistration($lens);
        }
    }
}
