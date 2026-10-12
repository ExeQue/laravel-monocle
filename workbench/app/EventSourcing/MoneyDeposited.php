<?php

declare(strict_types=1);

namespace Workbench\App\EventSourcing;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class MoneyDeposited extends ShouldBeStored
{
    public function __construct(
        public int $amount,
        public string $reference,
    ) {
    }
}
