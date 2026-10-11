<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Database;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Models\Post;

final class NPlusOneController
{
    use RendersResult;

    public function __invoke(): View
    {
        $counts = Post::query()->get()->mapWithKeys(fn (Post $post): array => [$post->title => $post->comments->count()]);

        return $this->result('N+1', $counts);
    }
}
