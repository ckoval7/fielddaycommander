<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperatingClass extends Model
{
    protected $fillable = [
        'event_type_id',
        'code',
        'name',
        'description',
        'allows_gota',
        'allows_free_vhf',
        'max_power_watts',
        'requires_emergency_power',
    ];

    protected function casts(): array
    {
        return [
            'allows_gota' => 'boolean',
            'allows_free_vhf' => 'boolean',
            'requires_emergency_power' => 'boolean',
        ];
    }
}
