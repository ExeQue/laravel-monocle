<?php

declare(strict_types=1);

namespace Workbench\App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RuntimeException;

/**
 * An event whose payload throws when it is read, so the events lens fails
 * to record it and the capture says so.
 */
final class ReportExported
{
    use Dispatchable;

    public array $rows {
        get => throw new RuntimeException('The report storage is offline.');
    }
}
