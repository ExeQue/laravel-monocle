<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;

/**
 * A bus command that is not queueable and fails when handled.
 */
final class ExplodingCommand
{
    use Dispatchable;

    public function handle(): void
    {
        throw new RuntimeException('command exploded');
    }
}
