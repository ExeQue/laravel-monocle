<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class Book extends Model
{
    protected $table = 'books';

    protected $guarded = [];

    public $timestamps = false;
}
