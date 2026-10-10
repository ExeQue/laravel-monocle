<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class OrderPlacedListener
{
    public static int $handled = 0;

    public function handle(OrderPlaced $event): void
    {
        self::$handled++;
    }
}
