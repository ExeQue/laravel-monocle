<?php

declare(strict_types=1);

namespace Workbench\App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Workbench\App\Models\Post;

final class PostPublished
{
    use Dispatchable;

    public function __construct(
        public readonly Post $post,
    ) {
    }
}
