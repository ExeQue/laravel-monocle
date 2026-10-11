<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

final class RolledBackTransactionController
{
    use RendersResult;

    public function __invoke(): View
    {
        DB::beginTransaction();
        Post::query()->create(['user_id' => User::query()->value('id'), 'title' => 'Never saved', 'body' => 'Rolled back.']);
        DB::rollBack();

        return $this->result('Rolled back transaction', Post::query()->where('title', 'Never saved')->count().' posts titled "Never saved"');
    }
}
