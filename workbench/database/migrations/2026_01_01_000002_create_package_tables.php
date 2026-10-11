<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        $vendor = dirname(__DIR__, 3).'/vendor';

        (require $vendor.'/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php')->up();
        (require $vendor.'/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub')->up();
    }
};
