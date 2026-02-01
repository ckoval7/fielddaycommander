<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Station extends Model
{
    /** @use HasFactory<\Database\Factories\StationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_configuration_id',
        'radio_equipment_id',
        'name',
        'power_source_description',
        'is_gota',
        'is_vhf_only',
        'is_satellite',
        'max_power_watts',
    ];

    protected function casts(): array
    {
        return [
            'is_gota' => 'boolean',
            'is_vhf_only' => 'boolean',
            'is_satellite' => 'boolean',
        ];
    }
}
