<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

interface Configurable
{
    /**
     * Receive the options array configured for this lens.
     *
     * @param  array<string, mixed>  $options
     */
    public function configure(array $options): void;
}
