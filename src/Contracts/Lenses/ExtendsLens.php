<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

use ExeQue\Monocle\Contracts\Lens;

interface ExtendsLens
{
    /**
     * The lens this one adds to. It writes into that lens's section, its
     * totals, warnings and errors count on that lens's tab, and it runs only
     * while that lens is enabled.
     *
     * @return class-string<Lens>
     */
    public static function extends(): string;
}
