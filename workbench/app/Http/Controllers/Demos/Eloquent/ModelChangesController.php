<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Eloquent;

use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

final class ModelChangesController
{
    use RendersResult;

    public function __invoke(): View
    {
        $post = Post::query()->create(['user_id' => User::query()->value('id'), 'title' => 'Draft', 'body' => 'First version.']);
        $post->update(['title' => 'Published', 'body' => 'Second version.']);
        $post->delete();

        return $this->result('Model changes', 'Created, updated and deleted post #'.$post->id);
    }
}
