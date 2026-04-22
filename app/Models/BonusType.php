<?php

namespace App\Models;

use Database\Factories\BonusTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BonusType extends Model
{
    /** @use HasFactory<BonusTypeFactory> */
    use HasFactory;

    /**
     * Resolve the BonusType row that matches a given code for the scoring
     * ruleset currently applied to the event. Uses resolved_rules_version so
     * events pinned to a yet-unregistered version pick the same fallback row
     * the RuleSetFactory uses for scoring.
     */
    public static function resolveFor(Event $event, string $code): ?self
    {
        return static::query()
            ->where('event_type_id', $event->event_type_id)
            ->where('rules_version', $event->resolved_rules_version)
            ->where('code', $code)
            ->first();
    }

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
