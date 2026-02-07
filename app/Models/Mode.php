<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mode extends Model
{
    /** @use HasFactory<\Database\Factories\ModeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'points_fd',
        'points_wfd',
        'description',
    ];
}
