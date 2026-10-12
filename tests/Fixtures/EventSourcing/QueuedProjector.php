<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

final class QueuedProjector extends Projector implements ShouldQueue
{
    public static int $handled = 0;

    public function onMoneyAdded(MoneyAdded $event): void
    {
        self::$handled++;
    }
}
