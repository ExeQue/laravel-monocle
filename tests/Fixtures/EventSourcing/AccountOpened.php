<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class AccountOpened extends ShouldBeStored
{
    public function __construct(
        public string $name,
    ) {
    }
}
