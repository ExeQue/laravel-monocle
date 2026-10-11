<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class CounterEvent
{
    public function __construct(
        public readonly int $value
    ) {
    }
}
