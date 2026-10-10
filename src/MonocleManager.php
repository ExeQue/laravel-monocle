<?php

declare(strict_types=1);

namespace ExeQue\Monocle;

use Closure;
use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Store;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Span;
use ExeQue\Monocle\Storage\StoreManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Traits\Conditionable;

/**
 * Target of the Monocle facade: user spans and metadata plus runtime
 * registration of lenses and store drivers.
 */
final class MonocleManager
{
    use Conditionable;

    public function __construct(
        private readonly Eyepiece $eyepiece,
        private readonly LensRegistry $registry,
        private readonly StoreManager $stores,
    ) {
    }

    /**
     * New span handle (not started). Pass a key to retrieve an open span from
     * another place without passing the object around.
     */
    public function span(?string $name = null, ?string $key = null): Span
    {
        return $this->eyepiece->span($name, 'user', $key);
    }

    /**
     * Run the callback inside a span and return its result.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  array<string, mixed>  $meta
     * @return T
     */
    public function measure(string $name, Closure $callback, array $meta = []): mixed
    {
        return $this->eyepiece->span($name)->meta($meta)->run($callback);
    }

    /**
     * Zero-length span marking a point in time.
     *
     * @param  array<string, mixed>  $meta
     */
    public function mark(string $name, array $meta = []): Span
    {
        return $this->eyepiece->span($name, 'mark')->start($meta)->stop();
    }

    /**
     * Attach metadata to the capture itself.
     *
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->eyepiece->meta($meta);

        return $this;
    }

    public function label(string $label): self
    {
        $this->eyepiece->describe(label: $label);

        return $this;
    }

    public function active(): ?Capture
    {
        return $this->eyepiece->active();
    }

    /**
     * Decide at runtime whether Monocle records at all. Call from a service
     * provider's register() method, e.g. Monocle::enabled(fn () => ! app()->isProduction()).
     * When disabled nothing is hooked into the framework.
     *
     * @param  bool|Closure(): bool  $enabled
     */
    public function enabled(bool|Closure $enabled): self
    {
        $this->eyepiece->setEnabled($enabled);

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->eyepiece->enabled();
    }

    /**
     * @param  class-string<Lens>  $lens
     * @param  array<string, mixed>|bool  $options
     */
    public function register(string $lens, array|bool $options = true): self
    {
        $this->registry->register($lens, $options);

        return $this;
    }

    /**
     * @param  class-string<Lens>  $lens
     */
    public function disable(string $lens): self
    {
        $this->registry->disable($lens);

        return $this;
    }

    /**
     * @param  class-string<Lens>  $lens
     */
    public function enable(string $lens): self
    {
        $this->registry->enable($lens);

        return $this;
    }

    /**
     * @return array<class-string<Lens>, array<string, mixed>|bool>
     */
    public function lenses(): array
    {
        return $this->registry->lenses();
    }

    /**
     * @param  Closure(Application, array<string, mixed>): Store  $factory
     */
    public function extendStore(string $driver, Closure $factory): self
    {
        $this->stores->extend($driver, $factory);

        return $this;
    }

    public function eyepiece(): Eyepiece
    {
        return $this->eyepiece;
    }
}
