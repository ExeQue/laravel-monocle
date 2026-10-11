<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $users = User::factory()->count(4)->create()->push($ada);

        Post::factory()
            ->count(10)
            ->recycle($users)
            ->has(Comment::factory()->count(3))
            ->create();
    }
}
