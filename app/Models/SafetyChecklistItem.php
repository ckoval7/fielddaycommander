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
     * @var array<string, list<array{label: string, is_required: bool, help_text: string|null}>>
     */
    public const DEFAULTS = [
        'safety_officer' => [
            [
                'label' => 'Safety Officer/s or qualified designated assistant/s was on site for the duration of the event',
                'is_required' => true,
                'help_text' => 'At least one designated Safety Officer (or qualified assistant) must be physically present from setup through teardown. This person should be clearly identified to all participants. Consider a hi-vis vest, lanyard, or sign at the entrance listing who they are and how to reach them.',
            ],
            [
                'label' => 'Fuel for generator properly stored',
                'is_required' => true,
                'help_text' => 'Store fuel in approved containers (UL-listed or ANSI-compliant) at least 20 feet from any generator, open flame, or ignition source. Keep containers in a shaded, ventilated area, never inside a tent or enclosed space. A spill kit or absorbent pads should be nearby.',
            ],
            [
                'label' => 'Fire extinguisher on hand and appropriately located',
                'is_required' => true,
                'help_text' => 'Place a minimum 5-lb ABC-rated fire extinguisher within 25 feet of each generator and fuel storage area. Additional extinguishers should be visible and accessible near cooking areas and electrical equipment. Verify the gauge is in the green "charged" zone and the inspection tag is current.',
            ],
            [
                'label' => 'First Aid kit on hand',
                'is_required' => true,
                'help_text' => 'Keep a well-stocked first aid kit in a known, accessible location announced to all participants. At minimum it should include adhesive bandages, gauze, antiseptic wipes, medical tape, instant cold packs, nitrile gloves, and a CPR pocket mask. Check that supplies are not expired.',
            ],
            [
                'label' => 'First Aid - CPR - AED versed else trained participant/s on site for full Field Day period',
                'is_required' => true,
                'help_text' => 'At least one person with current First Aid/CPR/AED certification (or equivalent training) must be on site for the entire event. If an AED is available, ensure it is powered on, pads are in-date, and its location is posted. Make sure all participants know who the trained responder is.',
            ],
            [
                'label' => 'Access to NWS alerts to monitor for inclement weather',
                'is_required' => true,
                'help_text' => 'Have at least one active weather monitoring source: a weather radio tuned to the local NWS frequency, a smartphone app with push alerts (e.g., Weather.gov, weather radio apps), or a dedicated NOAA receiver. Designate someone to monitor and announce warnings. Plan a shelter-in-place or evacuation procedure in advance.',
            ],
            [
                'label' => 'Tent stakes properly installed and marked',
                'is_required' => true,
                'help_text' => 'Drive stakes fully into the ground so no more than 1–2 inches protrude. Mark each stake with bright flagging tape, a tennis ball, or a reflective cap so they are visible in low light. Use guy-line tensioners and mark guy lines the same way. In high-wind areas, add extra stakes or use weighted anchors.',
            ],
            [
                'label' => 'Temporary antenna structures properly secured and marked',
                'is_required' => true,
                'help_text' => 'All masts, push-up poles, and temporary towers should have adequate guying (minimum three guy lines at 120° intervals) anchored with proper stakes or weights. Mark guy lines with visible flagging or reflective tape. Maintain safe distance from power lines (at least twice the structure height). Post warning signs near base areas.',
            ],
            [
                'label' => 'Site secured from tripping hazards',
                'is_required' => true,
                'help_text' => 'Route coax cables, extension cords, and ropes along edges or overhead whenever possible. Where cables must cross walkways, use cable ramps/covers or tape them flat with bright gaffer tape. Eliminate tripping hazards in high-traffic areas and paths to restrooms. After dark, ensure walkways are lit.',
            ],
            [
                'label' => 'Site is set up in a neat and orderly manner to reduce hazards',
                'is_required' => true,
                'help_text' => 'Keep operating positions organized with cables managed and excess coiled. Maintain clear walkways between stations and exits. Store personal gear, coolers, and supplies in designated areas, not in traffic paths. A tidy site signals professionalism to visitors and reduces accidents.',
            ],
            [
                'label' => 'Stations and equipment properly grounded',
                'is_required' => true,
                'help_text' => 'Each station should have a dedicated ground rod (minimum 4 feet) or be bonded to a common ground bus. Use short, heavy-gauge ground straps (not thin wire). Bond all equipment chassis together. Generators should have their own ground rod. This protects against RF burns, static buildup, and lightning-induced surges.',
            ],
            [
                'label' => 'Access to a means to contact police/fire/rescue if needed',
                'is_required' => true,
                'help_text' => 'Verify cell phone coverage at the site. If coverage is poor, identify the nearest landline or establish a radio link to someone with phone access. Post the site address, GPS coordinates, and local emergency numbers (police non-emergency, fire, EMS) at the main operating table so anyone can direct first responders.',
            ],
            [
                'label' => 'Safety Officer is designated point of contact for public safety officials',
                'is_required' => true,
                'help_text' => 'The Safety Officer\'s name and contact method should be posted visibly at the site entrance and communicated to all participants. If police, fire, or other officials arrive, the Safety Officer handles all communication. This prevents confusion from multiple people giving conflicting information.',
            ],
            [
                'label' => 'Minimize risks and control hazards to ensure no injuries to public',
                'is_required' => true,
                'help_text' => 'Walk the entire site perimeter looking for hazards a visitor might encounter: uneven ground, sharp tent stakes, hot generators, RF exposure zones near antennas. Mark or barricade hazardous areas. Consider the perspective of children, elderly visitors, or people unfamiliar with radio equipment.',
            ],
            [
                'label' => 'As necessary, monitoring participants for hydration and ensuring an adequate water supply is available',
                'is_required' => true,
                'help_text' => 'Provide bottled water or a clean cooler with cups at a central location. In hot weather, actively remind participants to drink water every 30–60 minutes. Watch for signs of heat exhaustion: heavy sweating, weakness, nausea, dizziness. Shade and rest areas should be available. Have electrolyte packets on hand for extended operations.',
            ],
        ],
        'site_responsibilities' => [
            [
                'label' => 'Organizer(s) and/or safety representative(s) were on site for the duration of the event',
                'is_required' => true,
                'help_text' => 'At least one organizer or designated safety representative must be on site from setup through teardown. This person is the go-to for decisions, visitor questions, and emergencies. Make sure participants know who they are. A posted sign, an announcement at the start, or a note to check the shift schedule all work well.',
            ],
            [
                'label' => 'Fuel for generator (if applicable) properly stored',
                'is_required' => false,
                'help_text' => 'Store fuel in approved containers (UL-listed or ANSI-compliant) at least 20 feet from any generator, open flame, or ignition source. Keep containers in a shaded, ventilated area, never inside a tent or enclosed space. A spill kit or absorbent pads should be nearby. If no generator is used, note as N/A.',
            ],
            [
                'label' => 'Fire extinguisher on hand and appropriately located',
                'is_required' => true,
                'help_text' => 'Place a minimum 5-lb ABC-rated fire extinguisher within 25 feet of each generator and fuel storage area. Additional extinguishers should be visible and accessible near cooking areas and electrical equipment. Verify the gauge is in the green "charged" zone and the inspection tag is current.',
            ],
            [
                'label' => 'First Aid kit on hand',
                'is_required' => true,
                'help_text' => 'Keep a well-stocked first aid kit in a known, accessible location announced to all participants. At minimum it should include adhesive bandages, gauze, antiseptic wipes, medical tape, instant cold packs, nitrile gloves, and a CPR pocket mask. Check that supplies are not expired.',
            ],
            [
                'label' => 'Access to NWS alerts to monitor for inclement weather',
                'is_required' => false,
                'help_text' => 'Have at least one active weather monitoring source: a weather radio tuned to the local NWS frequency, a smartphone app with push alerts (e.g., Weather.gov, weather radio apps), or a dedicated NOAA receiver. Designate someone to monitor and announce warnings. Plan a shelter-in-place or evacuation procedure in advance.',
            ],
            [
                'label' => 'Tent (if used) stakes properly installed and marked',
                'is_required' => false,
                'help_text' => 'Drive stakes fully into the ground so no more than 1–2 inches protrude. Mark each stake with bright flagging tape, a tennis ball, or a reflective cap so they are visible in low light. Use guy-line tensioners and mark guy lines the same way. In high-wind areas, add extra stakes or use weighted anchors. If no tents are used, note as N/A.',
            ],
            [
                'label' => 'Temporary antenna structures (if used) properly secured and marked',
                'is_required' => false,
                'help_text' => 'All masts, push-up poles, and temporary towers should have adequate guying (minimum three guy lines at 120° intervals) anchored with proper stakes or weights. Mark guy lines with visible flagging or reflective tape. Maintain safe distance from power lines (at least twice the structure height). Post warning signs near base areas. If no temporary antennas are used, note as N/A.',
            ],
            [
                'label' => 'Site secured from tripping hazards (coax cables, extension cords, etc.)',
                'is_required' => false,
                'help_text' => 'Route coax cables, extension cords, and ropes along edges or overhead whenever possible. Where cables must cross walkways, use cable ramps/covers or tape them flat with bright gaffer tape. Eliminate tripping hazards in high-traffic areas and paths to restrooms. After dark, ensure walkways are lit.',
            ],
            [
                'label' => 'Site is set up in a neat and orderly manner',
                'is_required' => true,
                'help_text' => 'Keep operating positions organized with cables managed and excess coiled. Maintain clear walkways between stations and exits. Store personal gear, coolers, and supplies in designated areas, not in traffic paths. A tidy site signals professionalism to visitors and reduces accidents.',
            ],
            [
                'label' => 'Stations and equipment properly grounded',
                'is_required' => true,
                'help_text' => 'Each station should have a dedicated ground rod (minimum 4 feet) or be bonded to a common ground bus. Use short, heavy-gauge ground straps (not thin wire). Bond all equipment chassis together. Generators should have their own ground rod. This protects against RF burns, static buildup, and lightning-induced surges.',
            ],
            [
                'label' => 'Access to a means to contact police/fire/rescue (if needed) is available',
                'is_required' => true,
                'help_text' => 'Verify cell phone coverage at the site. If coverage is poor, identify the nearest landline or establish a radio link to someone with phone access. Post the site address, GPS coordinates, and local emergency numbers (police non-emergency, fire, EMS) at the main operating table so anyone can direct first responders.',
            ],
            [
                'label' => 'Individual designated as a point of contact for visitors (ie, greeting the public or served-agency officials, providing verbal or written information about amateur radio)',
                'is_required' => true,
                'help_text' => 'Designate someone (or rotate the duty) to greet visitors, answer questions, and provide information about amateur radio and Field Day. Use the shift schedule to assign greeter slots so coverage is continuous. Have printed materials or a poster ready. This person should also be prepared to welcome served-agency officials and explain the group\'s capabilities.',
            ],
            [
                'label' => 'Monitoring participants/visitors for hydration and ensuring an adequate water supply (bottled water) is available',
                'is_required' => true,
                'help_text' => 'Provide bottled water or a clean cooler with cups at a central location. In hot weather, actively remind participants to drink water every 30–60 minutes. Watch for signs of heat exhaustion: heavy sweating, weakness, nausea, dizziness. Shade and rest areas should be available. Have electrolyte packets on hand for extended operations.',
            ],
        ],
    ];

    protected $fillable = [
        'event_configuration_id',
        'checklist_type',
        'label',
        'help_text',
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
        $classLetter = $operatingClass ? preg_replace('/\d+/', '', $operatingClass->code) : null;

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
                        'help_text' => $itemData['help_text'] ?? null,
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
