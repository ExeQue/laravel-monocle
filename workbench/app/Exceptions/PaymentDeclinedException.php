<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions;

use RuntimeException;

/**
 * The application's own exception, so the showcase has one Monocle counts as app.
 */
final class PaymentDeclinedException extends RuntimeException
{
}
