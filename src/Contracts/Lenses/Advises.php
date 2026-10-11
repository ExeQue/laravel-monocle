<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Contracts\Lenses;

interface Advises
{
    /**
     * One sentence for a coding agent on when to look at this lens's section
     * and what it tells. Shown in the agent skill; the field list stays in
     * description().
     */
    public function advice(): string;
}
