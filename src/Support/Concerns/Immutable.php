<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support\Concerns;

/**
 * Fluent methods return a changed copy; the instance itself never changes.
 *
 * @internal
 */
trait Immutable
{
    /**
     * An empty instance to build on with the fluent methods.
     */
    public static function make(): static
    {
        return new static();
    }

    /**
     * A new instance built from this one's properties, with the given ones
     * replaced. Every property must be a promoted constructor parameter.
     *
     * @param  array<string, mixed>  $properties  property => new value
     */
    protected function with(array $properties): static
    {
        return new static(...[...get_object_vars($this), ...$properties]);
    }
}
