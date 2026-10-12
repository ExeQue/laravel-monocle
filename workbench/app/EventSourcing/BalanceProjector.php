<?php

declare(strict_types=1);

namespace Workbench\App\EventSourcing;

use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Keeps each account's balance in the cache.
 */
final class BalanceProjector extends Projector
{
    public function onMoneyDeposited(MoneyDeposited $event): void
    {
        $key = 'balance:'.$event->aggregateRootUuid();

        Cache::put($key, Cache::get($key, 0) + $event->amount);
    }
}
