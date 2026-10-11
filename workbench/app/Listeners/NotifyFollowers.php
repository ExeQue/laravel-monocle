<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use Illuminate\Support\Facades\Log;
use Workbench\App\Events\PostPublished;

final class NotifyFollowers
{
    public function handle(PostPublished $event): void
    {
        Log::info('Followers notified about a new post.', ['post' => $event->post->id]);
    }
}
