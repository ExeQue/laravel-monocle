<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support\Concerns;

/**
 * Fluent methods return a changed copy; the instance itself never changes.
 */
trait Immutable
{
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
