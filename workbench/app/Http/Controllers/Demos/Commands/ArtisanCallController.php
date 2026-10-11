<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;

final class ArtisanCallController
{
    use RendersResult;

    public function __invoke(): View
    {
        Artisan::call('demo:greet', ['name' => 'Ada', '--shout' => true]);

        return $this->result('Artisan::call()', Artisan::output());
    }
}
