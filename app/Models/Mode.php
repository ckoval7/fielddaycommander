<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mode extends Model
{
    protected $fillable = [
        'name',
        'category',
        'points_fd',
        'points_wfd',
        'description',
    ];
}
