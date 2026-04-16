<?php

namespace App\Models;

use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Equipment model representing ham radio or club equipment.
 *
 * @property int $id
 * @property int|null $owner_user_id
 * @property int|null $owner_organization_id
 * @property int|null $managed_by_user_id
 * @property string|null $make
 * @property string|null $model
 * @property string $type
 * @property string|null $description
 * @property array|null $tags
 * @property string|null $value_usd
 * @property string|null $notes
 * @property string|null $serial_number
 * @property string|null $emergency_contact_phone
 * @property int|null $power_output_watts
 * @property string|null $photo_path
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $owner_name
 * @property-read bool $is_club_equipment
 * @property-read EquipmentEvent|null $current_commitment
 */
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * All valid equipment types with display metadata.
     *
     * @var array<string, array{label: string, icon: string}>
     */
    public const TYPES = [
        'radio' => ['label' => 'Radio', 'icon' => 'phosphor-radio'],
        'antenna' => ['label' => 'Antenna', 'icon' => 'phosphor-cell-signal-high'],
        'amplifier' => ['label' => 'Amplifier', 'icon' => 'phosphor-lightning'],
        'computer' => ['label' => 'Computer', 'icon' => 'phosphor-desktop'],
        'power_supply' => ['label' => 'Power Supply', 'icon' => 'phosphor-battery-full'],
        'accessory' => ['label' => 'Accessory', 'icon' => 'phosphor-wrench'],
        'tool' => ['label' => 'Tool', 'icon' => 'phosphor-wrench'],
        'furniture' => ['label' => 'Furniture', 'icon' => 'phosphor-house'],
        'other' => ['label' => 'Other', 'icon' => 'phosphor-cube'],
    ];

    /**
     * Get the list of valid type keys.
     *
     * @return array<int, string>
     */
    public static function typeKeys(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Get types formatted for select dropdowns (value/label pairs).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return collect(self::TYPES)
            ->map(fn (array $meta, string $key) => ['value' => $key, 'label' => $meta['label']])
            ->values()
            ->all();
    }

    /**
     * Get types formatted for filter dropdowns (id/name/icon).
     *
     * @return array<int, array{id: string, name: string, icon: string}>
     */
    public static function typeFilters(): array
    {
        return collect(self::TYPES)
            ->map(fn (array $meta, string $key) => ['id' => $key, 'name' => $meta['label'], 'icon' => $meta['icon']])
            ->values()
            ->all();
    }

    /**
     * Get the icon for a given equipment type.
     */
    public static function typeIcon(string $type): string
    {
        return self::TYPES[$type]['icon'] ?? 'phosphor-cube';
    }

    /**
     * Get the display label for a given equipment type.
     */
    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
    }

    protected $fillable = [
        'owner_user_id',
        'owner_organization_id',
        'managed_by_user_id',
        'make',
        'model',
        'type',
        'description',
        'tags',
        'value_usd',
        'notes',
        'serial_number',
        'emergency_contact_phone',
        'power_output_watts',
        'photo_path',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'value_usd' => 'decimal:2',
            'power_output_watts' => 'integer',
        ];
    }

    // Relationships

    /**
     * Get the user who owns this equipment.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Get the organization that owns this equipment.
     */
    public function owningOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owner_organization_id');
    }

    /**
     * Get the user managing this equipment.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by_user_id');
    }

    /**
     * Get the bands this equipment supports.
     */
    public function bands(): BelongsToMany
    {
        return $this->belongsToMany(Band::class, 'band_equipment');
    }

    /**
     * Get all equipment event commitments.
     */
    public function commitments(): HasMany
    {
        return $this->hasMany(EquipmentEvent::class);
    }

    /**
     * Get active commitments (committed/delivered for current or upcoming events).
     */
    public function activeCommitments(): HasMany
    {
        return $this->hasMany(EquipmentEvent::class)
            ->whereIn('status', ['committed', 'delivered'])
            ->whereHas('event', function (Builder $query) {
                $now = appNow();
                $query->where('start_time', '<=', $now->copy()->addDays(30))
                    ->where('end_time', '>=', $now);
            });
    }

    /**
     * Get all events this equipment is assigned to via the equipment_event pivot.
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'equipment_event')
            ->withPivot([
                'station_id',
                'assigned_by_user_id',
                'status',
                'committed_at',
                'expected_delivery_at',
                'delivery_notes',
                'manager_notes',
                'status_changed_at',
                'status_changed_by_user_id',
            ])
            ->withTimestamps();
    }

    // Scopes

    /**
     * Scope a query to only include equipment owned by a specific user.
     */
    public function scopeOwnedByUser(Builder $query, int $userId): Builder
    {
        return $query->where('owner_user_id', $userId);
    }

    /**
     * Scope a query to only include equipment owned by a specific organization.
     */
    public function scopeOwnedByOrganization(Builder $query, int $orgId): Builder
    {
        return $query->where('owner_organization_id', $orgId);
    }

    /**
     * Scope a query to only include equipment of a specific type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to search equipment by make, model, description, or serial number.
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search) {
            $q->where('make', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('serial_number', 'like', "%{$search}%");
        });
    }

    /**
     * Scope a query to filter equipment by commitment status relative to active/upcoming events.
     *
     * @param  string  $status  One of 'available' or 'committed'
     */
    public function scopeWithCommitmentStatus(Builder $query, string $status): Builder
    {
        $commitmentConstraint = function (Builder $q) {
            $q->whereIn('status', ['committed', 'delivered'])
                ->whereHas('event', function (Builder $eventQuery) {
                    $eventQuery->where('start_time', '<=', now()->addDays(30))
                        ->where('end_time', '>=', now());
                });
        };

        return match ($status) {
            'available' => $query->whereDoesntHave('commitments', $commitmentConstraint),
            'committed' => $query->whereHas('commitments', $commitmentConstraint),
            default => $query,
        };
    }

    /**
     * Scope a query to only include equipment that supports a specific band.
     */
    public function scopeWithBand(Builder $query, int $bandId): Builder
    {
        return $query->whereHas('bands', function (Builder $q) use ($bandId) {
            $q->where('bands.id', $bandId);
        });
    }

    /**
     * Scope a query to only include equipment available for a specific event.
     * Equipment is available if it's not committed to overlapping events.
     */
    public function scopeAvailableForEvent(Builder $query, int $eventId): Builder
    {
        return $query->whereDoesntHave('commitments', function (Builder $q) use ($eventId) {
            $q->whereHas('event', function (Builder $eventQuery) use ($eventId) {
                // Get the target event's date range
                $targetEvent = Event::findOrFail($eventId);

                // Find overlapping events
                $eventQuery->where('events.id', '!=', $eventId)
                    ->where(function (Builder $dateQuery) use ($targetEvent) {
                        // Event starts during target event
                        $dateQuery->whereBetween('events.start_time', [
                            $targetEvent->start_time,
                            $targetEvent->end_time,
                        ])
                            // Event ends during target event
                            ->orWhereBetween('events.end_time', [
                                $targetEvent->start_time,
                                $targetEvent->end_time,
                            ])
                            // Event completely encompasses target event
                            ->orWhere(function (Builder $encompassQuery) use ($targetEvent) {
                                $encompassQuery->where('events.start_time', '<=', $targetEvent->start_time)
                                    ->where('events.end_time', '>=', $targetEvent->end_time);
                            });
                    });
            })
                // Only consider active commitments
                ->whereIn('status', ['committed', 'delivered']);
        });
    }

    // Accessors

    /**
     * Get the owner's name (user or organization).
     */
    protected function ownerName(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if ($this->owner_organization_id) {
                    return 'Club Equipment';
                }

                if ($this->owner) {
                    return trim("{$this->owner->first_name} {$this->owner->last_name}");
                }

                return 'Unknown Owner';
            }
        );
    }

    /**
     * Check if this equipment is club-owned.
     */
    protected function isClubEquipment(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->owner_organization_id !== null
        );
    }

    /**
     * Get the current active commitment for this equipment.
     */
    protected function currentCommitment(): Attribute
    {
        return Attribute::make(
            get: function (): ?EquipmentEvent {
                $now = appNow();

                return $this->commitments()
                    ->whereIn('status', ['committed', 'delivered'])
                    ->whereHas('event', function (Builder $query) use ($now) {
                        $query->where('start_time', '<=', $now->copy()->addDays(30))
                            ->where('end_time', '>=', $now);
                    })
                    ->with('event')
                    ->first();
            }
        );
    }
}
