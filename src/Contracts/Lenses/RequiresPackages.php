<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

interface RequiresPackages
{
    /**
     * The Composer packages the lens records. Unless every one is installed
     * the lens is left out entirely: it is not booted, has no tab and adds
     * nothing to the summary.
     *
     * @return list<string>  package names, e.g. ['laravel/ai']
     */
    public static function packages(): array;
}
