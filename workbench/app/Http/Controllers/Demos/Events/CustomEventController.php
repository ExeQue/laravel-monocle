<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Events;

use Illuminate\View\View;
use Workbench\App\Events\PostPublished;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Models\Post;

final class CustomEventController
{
    use RendersResult;

    public function __invoke(): View
    {
        PostPublished::dispatch(Post::query()->firstOrFail());

        return $this->result('Custom event', 'PostPublished dispatched.');
    }
}
