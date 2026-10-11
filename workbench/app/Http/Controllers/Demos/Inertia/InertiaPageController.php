<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Inertia;

use Inertia\Inertia;
use Inertia\Response;
use Workbench\App\Models\Post;

final class InertiaPageController
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'user' => ['name' => 'Ada'],
            'posts' => Post::query()->limit(3)->pluck('title'),
            'greeting' => fn () => '',
            'account' => ['plan' => 'pro', 'owner' => ['name' => 'Ada', 'roles' => ['admin', 'editor']], 'limits' => ['posts' => 100, 'storage_mb' => 512]],
            'stats' => Inertia::defer(fn (): array => ['posts' => Post::query()->count()]),
            'activity' => Inertia::defer(fn (): array => ['today' => 3], 'sidebar'),
            'filters' => Inertia::optional(fn (): array => ['status' => 'open']),
            'feed' => Inertia::merge(['first', 'second']),
            'report' => array_fill(0, 2000, ['id' => 1, 'title' => 'A row in a large report']),
        ]);
    }
}
