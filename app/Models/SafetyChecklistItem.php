<?php

namespace App\Models;

use App\Enums\ChecklistType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SafetyChecklistItem extends Model
{
    /** @use HasFactory<\Database\Factories\SafetyChecklistItemFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Default checklist item definitions keyed by checklist type value.
     *
     * @var array<string, list<array{label: string, is_required: bool}>>
     */
    public const DEFAULTS = [
        'safety_officer' => [
            ['label' => 'Safety Officer/s or qualified designated assistant/s was on site for the duration of the event', 'is_required' => true],
            ['label' => 'Fuel for generator properly stored', 'is_required' => true],
            ['label' => 'Fire extinguisher on hand and appropriately located', 'is_required' => true],
            ['label' => 'First Aid kit on hand', 'is_required' => true],
            ['label' => 'First Aid - CPR - AED versed else trained participant/s on site for full Field Day period', 'is_required' => true],
            ['label' => 'Access to NWS alerts to monitor for inclement weather', 'is_required' => true],
            ['label' => 'Tent stakes properly installed and marked', 'is_required' => true],
            ['label' => 'Temporary antenna structures properly secured and marked', 'is_required' => true],
            ['label' => 'Site secured from tripping hazards', 'is_required' => true],
            ['label' => 'Site is set up in a neat and orderly manner to reduce hazards', 'is_required' => true],
            ['label' => 'Stations and equipment properly grounded', 'is_required' => true],
            ['label' => 'Access to a means to contact police/fire/rescue if needed', 'is_required' => true],
            ['label' => 'Safety Officer is designated point of contact for public safety officials', 'is_required' => true],
            ['label' => 'Minimize risks and control hazards to ensure no injuries to public', 'is_required' => true],
            ['label' => 'As necessary, monitoring participants for hydration and ensuring an adequate water supply is available', 'is_required' => true],
        ],
        'site_responsibilities' => [
            ['label' => 'Organizer(s) and/or safety representative(s) were on site for the duration of the event', 'is_required' => true],
            ['label' => 'Fuel for generator (if applicable) properly stored', 'is_required' => false],
            ['label' => 'Fire extinguisher on hand and appropriately located', 'is_required' => true],
            ['label' => 'First Aid kit on hand', 'is_required' => true],
            ['label' => 'Access to NWS alerts to monitor for inclement weather', 'is_required' => false],
            ['label' => 'Tent (if used) stakes properly installed and marked', 'is_required' => false],
            ['label' => 'Temporary antenna structures (if used) properly secured and marked', 'is_required' => false],
            ['label' => 'Site secured from tripping hazards (coax cables, extension cords, etc.)', 'is_required' => false],
            ['label' => 'Site is set up in a neat and orderly manner', 'is_required' => true],
            ['label' => 'Stations and equipment properly grounded', 'is_required' => true],
            ['label' => 'Access to a means to contact police/fire/rescue (if needed) is available', 'is_required' => true],
            ['label' => 'Individual designated as a point of contact for visitors (ie, greeting the public or served-agency officials, providing verbal or written information about amateur radio)', 'is_required' => true],
            ['label' => 'Monitoring participants/visitors for hydration and ensuring an adequate water supply (bottled water) is available', 'is_required' => true],
        ],
    ];

    protected $fillable = [
        'event_configuration_id',
        'checklist_type',
        'label',
        'is_required',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'checklist_type' => ChecklistType::class,
            'is_required' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    // Relationships

    /**
     * Get the event configuration this checklist item belongs to.
     */
    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    /**
     * Get the entry for this checklist item.
     */
    public function entry(): HasOne
    {
        return $this->hasOne(SafetyChecklistEntry::class);
    }

    // Scopes

    /**
     * Scope a query to only include items for a specific event configuration.
     */
    public function scopeForEvent(Builder $query, int $eventConfigurationId): Builder
    {
        return $query->where('event_configuration_id', $eventConfigurationId);
    }

    /**
     * Scope a query to filter by checklist type.
     */
    public function scopeByType(Builder $query, ChecklistType $type): Builder
    {
        return $query->where('checklist_type', $type);
    }

    /**
     * Scope a query to only include required items.
     */
    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
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
     * Seed default checklist items for an event configuration.
     *
     * Class A → safety_officer items
     * Class B/C/D/E → site_responsibilities items
     * Class F → both safety_officer and site_responsibilities items
     * Classes H/I/O/M → no checklist seeded
     */
    public static function seedDefaults(EventConfiguration $eventConfiguration): void
    {
        $operatingClass = $eventConfiguration->operatingClass;
        $classLetter = $operatingClass ? preg_replace('/[0-9]+/', '', $operatingClass->code) : null;

        $typesToSeed = match ($classLetter) {
            'A' => [ChecklistType::SafetyOfficer],
            'B', 'C', 'D', 'E' => [ChecklistType::SiteResponsibilities],
            'F' => [ChecklistType::SafetyOfficer, ChecklistType::SiteResponsibilities],
            default => [],
        };

        foreach ($typesToSeed as $type) {
            $defaults = self::DEFAULTS[$type->value] ?? [];
            $sortOrder = 0;

            foreach ($defaults as $itemData) {
                $item = self::firstOrCreate(
                    [
                        'event_configuration_id' => $eventConfiguration->id,
                        'checklist_type' => $type,
                        'label' => $itemData['label'],
                    ],
                    [
                        'is_required' => $itemData['is_required'],
                        'is_default' => true,
                        'sort_order' => $sortOrder,
                    ]
                );

                SafetyChecklistEntry::firstOrCreate(
                    [
                        'safety_checklist_item_id' => $item->id,
                    ],
                    [
                        'is_completed' => false,
                    ]
                );

                $sortOrder++;
            }
        }
    }
}
