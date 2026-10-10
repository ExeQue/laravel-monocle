<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Exceptions;

use InvalidArgumentException;

class InvalidSpanColorException extends InvalidArgumentException implements MonocleExceptionInterface
{
    public static function forValue(string $color): self
    {
        return new self("Unknown span colour [{$color}]. Use a SpanColor case or a hex colour such as #c08429.");
    }
}
