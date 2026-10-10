<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Exceptions;

use LogicException;

class LensAlreadyBootedException extends LogicException implements MonocleExceptionInterface
{
    public static function forRegistration(string $lens): self
    {
        return new self("Cannot register lens [{$lens}]: lenses are already booted. Register lenses from a service provider's register() method.");
    }
}
