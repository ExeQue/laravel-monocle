<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Spans;

use ExeQue\Monocle\Facades\Monocle;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Models\Post;

final class CustomSpansController
{
    use RendersResult;

    public function __invoke(): View
    {
        $span = Monocle::span('prepare report')->start();
        usleep(30_000);
        $span->stop();

        $total = Monocle::measure('sum posts', fn (): int => Post::query()->count(), ['source' => 'demo']);

        return $this->result('Custom spans', ['posts' => $total]);
    }
}
