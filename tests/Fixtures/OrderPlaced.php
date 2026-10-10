<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class OrderPlaced
{
    public function __construct(public readonly int $orderId, public readonly string $customer)
    {
    }
}
