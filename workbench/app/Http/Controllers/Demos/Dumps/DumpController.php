<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Dumps;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

final class DumpController
{
    use RendersResult;

    public function __invoke(): View
    {
        // The dumps are recorded; their output is kept off the page.
        ob_start();
        dump(['user' => 'Ada', 'roles' => ['admin', 'editor']]);
        dump(User::query()->firstOrFail(), now());
        dump($this->postWithRelations());
        ob_end_clean();

        return $this->result('dump()', 'Dumped an array, a model, a date and a model holding other models.');
    }

    /**
     * A post with its author and comments loaded, built in memory so nothing is written.
     */
    private function postWithRelations(): Post
    {
        $post = new Post(['title' => 'Monocle 1.0', 'body' => 'Profiling for agents.']);
        $post->setRelation('user', User::query()->firstOrFail());
        $post->setRelation('comments', new Collection([
            new Comment(['body' => 'First!']),
            new Comment(['body' => 'Does it work with Octane?']),
        ]));

        return $post;
    }
}
