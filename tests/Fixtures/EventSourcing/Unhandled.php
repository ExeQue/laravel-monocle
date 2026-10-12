<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * An event no projector or reactor handles.
 */
final class Unhandled extends ShouldBeStored
{
}
