<?php

declare(strict_types=1);

namespace Workbench\App\EventSourcing;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class AccountOpened extends ShouldBeStored
{
    public function __construct(
        public string $owner,
        public string $api_token,
    ) {
    }
}
