<?php

namespace App\Models;

use Database\Factories\ModeRulePointsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModeRulePoints extends Model
{
    /** @use HasFactory<ModeRulePointsFactory> */
    use HasFactory;

    protected $table = 'mode_rule_points';

    protected $fillable = [
        'event_type_id',
        'rules_version',
        'mode_id',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function mode(): BelongsTo
    {
        return $this->belongsTo(Mode::class);
    }
}
