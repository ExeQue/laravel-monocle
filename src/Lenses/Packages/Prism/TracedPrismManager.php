<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\Prism;

use Closure;
use ExeQue\Monocle\Eyepiece;
use Illuminate\Contracts\Foundation\Application;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;

/**
 * Wraps the Prism manager so every provider it resolves records its calls.
 *
 * @internal
 */
final class TracedPrismManager extends PrismManager
{
    public function __construct(
        private readonly PrismManager $inner,
        private readonly Eyepiece $eyepiece,
        private readonly ?int $maxText,
        Application $app,
    ) {
        parent::__construct($app);
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
    {
        return new TracedProvider($this->inner->resolve($name, $providerConfig), $this->eyepiece, $this->maxText);
    }

    public function extend(string $provider, Closure $callback): self
    {
        $this->inner->extend($provider, $callback);

        return $this;
    }

    public function inner(): PrismManager
    {
        return $this->inner;
    }
}
