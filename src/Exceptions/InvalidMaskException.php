<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Exceptions;

use InvalidArgumentException;

class InvalidMaskException extends InvalidArgumentException implements MonocleExceptionInterface
{
    public static function forKey(string $key): self
    {
        return new self("Invalid mask for [{$key}]. Use a class name or an array with the class name first and its constructor arguments after it.");
    }
}
