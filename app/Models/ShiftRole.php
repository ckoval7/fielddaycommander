<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\ShiftRoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShiftRole extends Model
{
    /** @use HasFactory<ShiftRoleFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Default shift role definitions keyed by name.
     * 'eligible_classes' is informational — used for seeding recommendations.
     *
     * @var array<string, array{description: string, bonus_points: int|null, requires_confirmation: bool, eligible_classes: list<string>, icon: string, color: string}>
     */
    public const DEFAULTS = [
        'Safety Officer' => [
            'description' => 'Checks the items on the Safety Officer Checklist',
            'bonus_points' => 100,
            'requires_confirmation' => true,
            'eligible_classes' => ['A'],
            'icon' => 'phosphor-shield-check',
            'color' => '#dc2626',
        ],
        'Site Responsibilities' => [
            'description' => 'Checks the site for hazards and serves as a contact for visitors',
            'bonus_points' => 50,
            'requires_confirmation' => true,
            'eligible_classes' => ['B', 'C', 'D', 'E', 'F'],
            'icon' => 'phosphor-clipboard-text',
            'color' => '#f59e0b',
        ],
        'Public Information Table' => [
            'description' => 'Staffs the public information table with handouts for visitors',
            'bonus_points' => 100,
            'requires_confirmation' => true,
            'eligible_classes' => ['A', 'B', 'F'],
            'icon' => 'phosphor-info',
            'color' => '#3b82f6',
        ],
        'Public Greeter' => [
            'description' => 'Wears a name badge and greets visitors at a publicly accessible location',
            'bonus_points' => 100,
            'requires_confirmation' => true,
            'eligible_classes' => ['A', 'B', 'F'],
            'icon' => 'phosphor-hand',
            'color' => '#10b981',
        ],
        'GOTA Coach' => [
            'description' => 'Coaches new operators at the GOTA station',
            'bonus_points' => 100,
            'requires_confirmation' => true,
            'eligible_classes' => ['A', 'F'],
            'icon' => 'phosphor-graduation-cap',
            'color' => '#8b5cf6',
        ],
        'Message Handler' => [
            'description' => 'Handles NTS/ICS-213 message origination, relay, and delivery',
            'bonus_points' => 100,
            'requires_confirmation' => true,
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'icon' => 'phosphor-envelope',
            'color' => '#ec4899',
        ],
        'Event Manager' => [
            'description' => 'Overall event coordination and management',
            'bonus_points' => null,
            'requires_confirmation' => false,
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F', 'H', 'I', 'O', 'M'],
            'icon' => 'phosphor-users-three',
            'color' => '#6366f1',
        ],
        'Station Captain' => [
            'description' => 'Manages a specific radio station and its operators',
            'bonus_points' => null,
            'requires_confirmation' => false,
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F', 'H', 'I', 'O', 'M'],
            'icon' => 'phosphor-radio',
            'color' => '#64748b',
        ],
        'Operator' => [
            'description' => 'Licensed amateur radio operator making contacts at a station',
            'bonus_points' => null,
            'requires_confirmation' => false,
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F', 'H', 'I', 'O', 'M'],
            'icon' => 'phosphor-cell-signal-high',
            'color' => '#0ea5e9',
        ],
    ];

    /**
     * Default roles for unscored events (public service, nets, drills).
     * None of them earn bonus points.
     *
     * @var array<string, array{description: string, tactical_callsign: string|null, icon: string, color: string}>
     */
    public const UNSCORED_DEFAULTS = [
        'Net Control' => [
            'description' => 'Runs the net and keeps the net log',
            'tactical_callsign' => 'Net Control',
            'icon' => 'phosphor-broadcast',
            'color' => '#dc2626',
        ],
        'Event Manager' => [
            'description' => 'Overall event coordination and liaison with the served agency',
            'tactical_callsign' => null,
            'icon' => 'phosphor-users-three',
            'color' => '#6366f1',
        ],
        'Message Handler' => [
            'description' => 'Handles ICS-213 and radiogram traffic for the served agency',
            'tactical_callsign' => null,
            'icon' => 'phosphor-envelope',
            'color' => '#ec4899',
        ],
        'Operator' => [
            'description' => 'Licensed amateur radio operator staffing a position',
            'tactical_callsign' => null,
            'icon' => 'phosphor-cell-signal-high',
            'color' => '#0ea5e9',
        ],
    ];

    /**
     * Maps shift role names to BonusType codes for automatic bonus awarding.
     *
     * Only roles where confirmed presence IS the bonus requirement are mapped here.
     * Roles like Safety Officer and Site Responsibilities require additional
     * verification (checklists) and are handled by their own features.
     * GOTA Coach requires 10 supervised contacts — tracked via GOTA contact count.
     * Message Handler requires actual messages sent — tracked separately.
     *
     * @var array<string, string>
     */
    public const BONUS_TYPE_MAP = [
        'Public Information Table' => 'public_info_booth',
    ];

    /**
     * Roles where having a confirmed person makes the event ELIGIBLE for the
     * bonus, but additional verification is needed before points are awarded.
     * The value describes what else is required.
     *
     * @var array<string, string>
     */
    public const BONUS_ELIGIBILITY_MAP = [
        'Safety Officer' => 'Requires completed Safety Officer Checklist',
        'Site Responsibilities' => 'Requires completed Site Responsibilities Checklist (present from setup to breakdown)',
        'GOTA Coach' => 'Requires at least 10 GOTA contacts made under supervision',
        'Message Handler' => 'Requires formal NTS/ICS-213 messages originated or relayed',
    ];

    protected $fillable = [
        'event_configuration_id',
        'name',
        'tactical_callsign',
        'description',
        'is_default',
        'bonus_points',
        'requires_confirmation',
        'icon',
        'color',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'requires_confirmation' => 'boolean',
        ];
    }

    // Relationships

    /**
     * Get the event configuration this shift role belongs to.
     */
    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    /**
     * Get the shifts for this role.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    // Scopes

    /**
     * Scope a query to only include roles for a specific event configuration.
     */
    public function scopeForEvent(Builder $query, int $eventConfigurationId): Builder
    {
        return $query->where('event_configuration_id', $eventConfigurationId);
    }

    /**
     * Scope a query to only include default roles.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to only include custom (non-default) roles.
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_default', false);
    }

    /**
     * Scope a query to order by sort_order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    // Methods

    /**
     * Get the BonusType code for auto-awarding, if this role qualifies.
     */
    public function getBonusTypeCode(): ?string
    {
        return self::BONUS_TYPE_MAP[$this->name] ?? null;
    }

    /**
     * Check if this role only contributes to bonus eligibility (not auto-award).
     */
    public function isBonusEligibilityOnly(): bool
    {
        return isset(self::BONUS_ELIGIBILITY_MAP[$this->name]);
    }

    /**
     * Get the additional requirement description for eligibility-only roles.
     */
    public function getBonusEligibilityRequirement(): ?string
    {
        return self::BONUS_ELIGIBILITY_MAP[$this->name] ?? null;
    }

    /**
     * Seed default shift roles for an event configuration.
     *
     * Only seeds roles whose eligible_classes include the event's operating class letter.
     * Unscored events get their own roles, with no bonus points.
     */
    public static function seedDefaults(EventConfiguration $eventConfiguration): void
    {
        $isScored = $eventConfiguration->isScored();
        $operatingClass = $isScored ? $eventConfiguration->operatingClass : null;
        $classLetter = $operatingClass ? preg_replace('/\d+/', '', $operatingClass->code) : null;

        $sortOrder = 0;
        foreach ($isScored ? self::DEFAULTS : self::UNSCORED_DEFAULTS as $name => $config) {
            if ($classLetter && ! in_array($classLetter, $config['eligible_classes'])) {
                continue;
            }

            self::firstOrCreate(
                [
                    'event_configuration_id' => $eventConfiguration->id,
                    'name' => $name,
                ],
                [
                    'description' => $config['description'],
                    'tactical_callsign' => $config['tactical_callsign'] ?? null,
                    'is_default' => true,
                    'bonus_points' => $config['bonus_points'] ?? null,
                    'requires_confirmation' => $config['requires_confirmation'] ?? false,
                    'icon' => $config['icon'],
                    'color' => $config['color'],
                    'sort_order' => $sortOrder,
                ]
            );

            $sortOrder++;
        }
    }
}
