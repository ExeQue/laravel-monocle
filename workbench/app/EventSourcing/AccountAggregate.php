<?php

declare(strict_types=1);

namespace Workbench\App\EventSourcing;

use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

final class AccountAggregate extends AggregateRoot
{
    public int $balance = 0;

    public function open(string $owner): self
    {
        return $this->recordThat(new AccountOpened($owner, 'tok_'.bin2hex(random_bytes(8))));
    }

    public function deposit(int $amount, string $reference): self
    {
        return $this->recordThat(new MoneyDeposited($amount, $reference));
    }

    protected function applyMoneyDeposited(MoneyDeposited $event): void
    {
        $this->balance += $event->amount;
    }
}
