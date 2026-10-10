<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Exceptions;

use InvalidArgumentException;

class InvalidStoreDriverException extends InvalidArgumentException implements MonocleExceptionInterface
{
    public static function forDriver(string $driver): self
    {
        return new self("Unknown lens storage driver [{$driver}]. Use file, cache, a class implementing the Store contract, or a driver registered with Monocle::extendStore().");
    }
}
