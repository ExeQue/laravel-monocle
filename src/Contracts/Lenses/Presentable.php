<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

use ExeQue\Monocle\Dashboard\Presentation;

interface Presentable
{
    /**
     * How the dashboard shows this lens's section: the tab's label, the
     * table's columns, the fields kept for the expanded row, groupings, and
     * optionally a Blade view for the expanded row.
     */
    public function presentation(): Presentation;
}
