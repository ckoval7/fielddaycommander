<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperatingSession extends Model
{
    /** @use HasFactory<\Database\Factories\OperatingSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'station_id',
        'operator_user_id',
        'band_id',
        'mode_id',
        'start_time',
        'end_time',
        'qso_count',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
        ];
    }
}
