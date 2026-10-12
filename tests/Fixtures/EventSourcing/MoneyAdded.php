<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class MoneyAdded extends ShouldBeStored
{
    public function __construct(
        public int $amount,
        public string $token = 'tok_1234567890',
    ) {
    }
}
