<?php

namespace App\Models;

use Database\Factories\BonusTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BonusType extends Model
{
    /** @use HasFactory<BonusTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'event_type_id',
        'rules_version',
        'code',
        'name',
        'description',
        'base_points',
        'is_per_transmitter',
        'is_per_occurrence',
        'max_points',
        'max_occurrences',
        'requires_proof',
        'eligible_classes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_per_transmitter' => 'boolean',
            'is_per_occurrence' => 'boolean',
            'requires_proof' => 'boolean',
            'eligible_classes' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
