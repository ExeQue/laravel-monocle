<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

final class BalanceProjector extends Projector
{
    /** @var list<int> */
    public static array $added = [];

    public function onMoneyAdded(MoneyAdded $event): void
    {
        self::$added[] = $event->amount;
    }
}
