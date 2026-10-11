<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Exceptions;

use InvalidArgumentException;

class InvalidSummaryTotalException extends InvalidArgumentException implements MonocleExceptionInterface
{
    public static function withoutValue(string $name): self
    {
        return new self("The total [{$name}] has no value. Give it one, or give several totals at once as a name => value map.");
    }
}
