<?php

declare(strict_types=1);

namespace Tests\Fixtures\Components;

use Illuminate\View\Component;

final class InlineGreeting extends Component
{
    public function render(): string
    {
        return '<b>Hi</b>';
    }
}
