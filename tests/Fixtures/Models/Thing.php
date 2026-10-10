<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class Thing extends Model
{
    protected $table = 'things';

    protected $guarded = [];

    protected $hidden = ['secret'];

    public $timestamps = false;
}
