<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Views;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class RepeatedViewController
{
    use RendersResult;

    public function __invoke(): View
    {
        $html = '';

        foreach (range(1, 12) as $row) {
            $html .= view('partials.slow-row', ['row' => $row])->render();
        }

        return $this->result('Repeated view', strip_tags($html));
    }
}
