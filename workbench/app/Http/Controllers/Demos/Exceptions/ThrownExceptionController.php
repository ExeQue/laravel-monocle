<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Exceptions;

use RuntimeException;

final class ThrownExceptionController
{
    public function __invoke(): never
    {
        throw new RuntimeException('Thrown on purpose.');
    }
}
