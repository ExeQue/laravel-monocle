<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];
}
