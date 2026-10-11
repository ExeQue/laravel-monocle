<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\RequiresPackages;

/**
 * A lens for a package that is not installed; it must never boot.
 */
final class MissingPackageLens implements Lens, RequiresPackages
{
    public static int $booted = 0;

    public function key(): string
    {
        return 'missing';
    }

    public static function packages(): array
    {
        return ['laravel/framework', 'acme/not-installed'];
    }

    public function description(): string
    {
        return 'Records the not installed package.';
    }

    public function boot(): void
    {
        self::$booted++;
    }
}
