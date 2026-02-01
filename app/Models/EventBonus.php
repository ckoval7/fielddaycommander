<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventBonus extends Model
{
    /** @use HasFactory<\Database\Factories\EventBonusFactory> */
    use HasFactory;

    protected $fillable = [
        'event_configuration_id',
        'bonus_type_id',
        'claimed_by_user_id',
        'quantity',
        'calculated_points',
        'notes',
        'proof_file_path',
        'is_verified',
        'verified_by_user_id',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }
}
