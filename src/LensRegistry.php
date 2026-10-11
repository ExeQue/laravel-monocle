<?php

declare(strict_types=1);

namespace ExeQue\Monocle;

use Composer\InstalledVersions;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Configurable;
use ExeQue\Monocle\Contracts\Lenses\ExtendsLens;
use ExeQue\Monocle\Contracts\Lenses\Finalizable;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Exceptions\LensAlreadyBootedException;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the effective lens list (config merged with runtime registrations)
 * and drives boot/finalize/reset across all enabled lenses.
 *
 * @internal
 */
final class LensRegistry
{
    /** @var array<class-string<Lens>, array<string, mixed>|bool> */
    private array $overrides = [];

    /** @var list<Lens> */
    private array $booted = [];

    private bool $isBooted = false;

    public function __construct(
        private readonly Application $app
    ) {
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
        $enabled = array_keys(array_filter(
            $this->lenses(),
            fn (array|bool $options, string $class): bool => $options !== false && self::available($class),
            ARRAY_FILTER_USE_BOTH,
        ));

        // An extension runs only while the lens it adds to does.
        return array_values(array_filter(
            $enabled,
            fn (string $class): bool => ! is_subclass_of($class, ExtendsLens::class) || in_array($class::extends(), $enabled, true),
        ));
    }

    /**
     * The section a lens writes into: its own, or that of the lens it extends.
     */
    public function sectionOf(Lens $lens): string
    {
        if (! $lens instanceof ExtendsLens) {
            return $lens->key();
        }

        foreach ($this->booted as $booted) {
            if ($booted::class === $lens::extends()) {
                return $booted->key();
            }
        }

        return $this->app->make($lens::extends())->key();
    }

    /**
     * Whether the packages a lens records are installed.
     */
    public static function available(string $class): bool
    {
        if (! is_subclass_of($class, RequiresPackages::class)) {
            return true;
        }

        foreach ($class::packages() as $package) {
            if (! InstalledVersions::isInstalled($package)) {
                return false;
            }
        }

        return true;
    }

    public function boot(Eyepiece $eyepiece): void
    {
        if ($this->isBooted) {
            return;
        }

        $this->isBooted = true;

        foreach ($this->enabled() as $class) {
            $options = $this->lenses()[$class];

            /** @var Lens $lens */
            $lens = $this->app->make($class);

            if ($lens instanceof Configurable && is_array($options)) {
                $lens->configure($options);
            }

            if (method_exists($lens, 'boot')) {
                $this->app->call([$lens, 'boot']);
            }

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
     * lenses have been booted in this process (the MCP server reads captures
     * even when recording is off).
     *
     * @return array<string, string>
     */
    /**
     * How Presentable lenses want their sections shown, keyed by section.
     *
     * @return array<string, Presentation>
     */
    public function presentations(): array
    {
        $presentations = [];

        foreach ($this->booted as $lens) {
            if ($lens instanceof Presentable) {
                $presentation = $lens->presentation();
                $presentations = [...$presentation->included, ...$presentations, $lens->key() => $presentation];
            }
        }

        return $presentations;
    }

    public function descriptions(): array
    {
        $descriptions = [];
        $lenses = $this->booted !== [] ? $this->booted : array_map($this->app->make(...), $this->enabled());

        foreach ($lenses as $lens) {
            if (! $lens instanceof ExtendsLens) {
                $descriptions[$lens->key()] = $lens->description();
            }
        }

        // What extensions record is part of the section they write into.
        foreach ($lenses as $lens) {
            if ($lens instanceof ExtendsLens) {
                $section = $this->sectionOf($lens);
                $descriptions[$section] = trim(($descriptions[$section] ?? '').' '.$lens->description());
            }
        }

        return $descriptions;
    }

    /**
     * Section key => when to look at it, for every enabled lens: its advice,
     * or the first sentence of its description when it gives none.
     *
     * @return array<string, string>
     */
    public function advice(): array
    {
        $advice = [];
        $lenses = $this->booted !== [] ? $this->booted : array_map($this->app->make(...), $this->enabled());

        foreach ($lenses as $lens) {
            if ($lens instanceof ExtendsLens) {
                continue;
            }

            $advice[$lens->key()] = $lens instanceof Advises
                ? $lens->advice()
                : (preg_match('/^.+?[.!?](?=\s|$)/s', $lens->description(), $match) === 1 ? $match[0] : $lens->description());
        }

        return $advice;
    }

    /**
     * @return list<Summarizable>
     */
    public function summarizers(): array
    {
        return array_values(array_filter($this->booted, fn (Lens $lens): bool => $lens instanceof Summarizable));
    }

    public function finalize(Capture $capture): void
    {
        foreach ($this->booted as $lens) {
            if ($lens instanceof Finalizable) {
                $lens->finalize($capture);
            }
        }
    }

    public function reset(): void
    {
        foreach ($this->booted as $lens) {
            if ($lens instanceof Resettable) {
                $lens->reset();
            }
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
