<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

final class AccountAggregate extends AggregateRoot
{
    public int $balance = 0;

    public function addMoney(int $amount): self
    {
        return $this->recordThat(new MoneyAdded($amount));
    }

    protected function applyMoneyAdded(MoneyAdded $event): void
    {
        $this->balance += $event->amount;
    }
}
