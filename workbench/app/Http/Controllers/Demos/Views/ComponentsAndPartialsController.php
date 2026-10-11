<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Views;

use Illuminate\View\View;
use Workbench\App\Models\Post;

final class ComponentsAndPartialsController
{
    public function __invoke(): View
    {
        return view('views-demo', [
            'tags' => array_map(fn (int $i): string => 'tag '.$i, range(1, 15)),
            'posts' => Post::query()->withCount('comments')->limit(5)->get(),
        ]);
    }
}
