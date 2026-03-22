<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShiftRole extends Model
{
    /** @use HasFactory<\Database\Factories\ShiftRoleFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Default shift roles with their configurations.
     *
     * @var array<string, array{description: string, eligible_classes: list<string>, bonus_points: int|null, requires_confirmation: bool, icon: string, color: string}>
     */
    public const DEFAULTS = [
        'Station Operator' => [
            'description' => 'Operates a radio station during the shift',
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'bonus_points' => null,
            'requires_confirmation' => false,
            'icon' => 'o-radio',
            'color' => 'badge-primary',
        ],
        'GOTA Coach' => [
            'description' => 'Coaches new operators at the GOTA station',
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'bonus_points' => null,
            'requires_confirmation' => false,
            'icon' => 'o-academic-cap',
            'color' => 'badge-secondary',
        ],
        'Safety Officer' => [
            'description' => 'Designated safety officer for the event',
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'bonus_points' => 100,
            'requires_confirmation' => true,
            'icon' => 'o-shield-check',
            'color' => 'badge-warning',
        ],
        'Public Information Table' => [
            'description' => 'Staffs the public information table for visitors',
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'bonus_points' => 100,
            'requires_confirmation' => true,
            'icon' => 'o-information-circle',
            'color' => 'badge-info',
        ],
        'Public Greeter' => [
            'description' => 'Greets visitors at a publicly accessible location',
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'bonus_points' => 100,
            'requires_confirmation' => true,
            'icon' => 'o-hand-raised',
            'color' => 'badge-success',
        ],
        'Logger' => [
            'description' => 'Logs contacts at a station',
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'bonus_points' => null,
            'requires_confirmation' => false,
            'icon' => 'o-pencil-square',
            'color' => 'badge-neutral',
        ],
        'Setup / Teardown' => [
            'description' => 'Helps with site setup and teardown',
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'bonus_points' => null,
            'requires_confirmation' => false,
            'icon' => 'o-wrench-screwdriver',
            'color' => 'badge-accent',
        ],
        'General Volunteer' => [
            'description' => 'General volunteer duties as assigned',
            'eligible_classes' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'bonus_points' => null,
            'requires_confirmation' => false,
            'icon' => 'o-user-group',
            'color' => 'badge-ghost',
        ],
    ];

    /**
     * Maps shift role names to BonusType codes for automatic bonus tracking.
     *
     * @var array<string, string>
     */
    public const BONUS_TYPE_MAP = [
        'Safety Officer' => 'safety_officer',
        'Public Information Table' => 'public_info_booth',
        'Public Greeter' => 'public_location',
    ];

    protected $fillable = [
        'event_configuration_id',
        'name',
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
     * Get the BonusType code associated with this shift role, if any.
     */
    public function getBonusTypeCode(): ?string
    {
        return self::BONUS_TYPE_MAP[$this->name] ?? null;
    }

    /**
     * Seed default shift roles for an event configuration.
     *
     * Only seeds roles whose eligible_classes include the event's operating class letter.
     */
    public static function seedDefaults(EventConfiguration $eventConfiguration): void
    {
        $operatingClass = $eventConfiguration->operatingClass;
        $classLetter = $operatingClass ? preg_replace('/[0-9]+/', '', $operatingClass->code) : null;

        $sortOrder = 0;
        foreach (self::DEFAULTS as $name => $config) {
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
                    'is_default' => true,
                    'bonus_points' => $config['bonus_points'],
                    'requires_confirmation' => $config['requires_confirmation'],
                    'icon' => $config['icon'],
                    'color' => $config['color'],
                    'sort_order' => $sortOrder,
                ]
            );

            $sortOrder++;
        }
    }
}
