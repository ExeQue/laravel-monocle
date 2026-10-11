<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $guarded = [];
}
