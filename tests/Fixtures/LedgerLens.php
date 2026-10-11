<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Dashboard\Presentation;

/**
 * A presentable lens that shows another section on its tab and describes
 * itself without a full sentence.
 */
final class LedgerLens implements Lens, Presentable
{
    public function key(): string
    {
        return 'ledger';
    }

    public function description(): string
    {
        return 'Ledger entries';
    }

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->label('Ledger')
            ->includes(['ledger_notes' => Presentation::table()->label('Notes'), 'payments' => Presentation::table()->label('Included payments')]);
    }
}
