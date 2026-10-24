<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Enums\ChecklistAnswerType;
use App\Enums\ChecklistCompletionRule;
use App\Enums\ChecklistScope;
use App\Models\Checklist;
use App\Models\EventConfiguration;
use App\Models\ShiftRole;

/**
 * The built-in checklists an event can start from.
 *
 * The ARRL Safety Officer and Site Responsibilities lists follow the ARRL
 * Field Day packet and earn their bonus through the bonus code, so their
 * items are marked default and can't be deleted.
 */
class ChecklistTemplates
{
    /**
     * @var array<string, array{name: string, description: string, scope: string, completion_rule: string, bonus_code: string|null, shift_roles: list<string>, locked: bool, items: list<array{label: string, is_required: bool, help_text: string|null, answer_type?: string}>}>
     */
    public const TEMPLATES = [
        'arrl_safety_officer' => [
            'name' => 'Safety Officer',
            'description' => 'ARRL Field Day safety checks for Class A entries. Complete every item to record the bonus automatically.',
            'scope' => 'event',
            'completion_rule' => 'all_items',
            'bonus_code' => 'safety_officer',
            'shift_roles' => ['Safety Officer'],
            'locked' => true,
            'items' => [
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
                    'help_text' => 'Have at least one active weather monitoring source: a weather radio tuned to the local NWS frequency, an app that receives NWS alerts, or a dedicated NOAA receiver. Designate someone to monitor and announce warnings. Plan a shelter-in-place or evacuation procedure in advance.',
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
                    'help_text' => 'Keep operating positions organized with cables managed and excess coiled. Maintain clear walkways between stations and exits. Store personal gear, coolers, and supplies in designated areas, not in traffic paths.',
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
        ],
        'arrl_site_responsibilities' => [
            'name' => 'Site Responsibilities',
            'description' => 'ARRL Field Day site checks for Classes B through F. Complete every required item and more than half of all items to record the bonus automatically.',
            'scope' => 'event',
            'completion_rule' => 'required_and_majority',
            'bonus_code' => 'site_responsibilities',
            'shift_roles' => ['Site Responsibilities'],
            'locked' => true,
            'items' => [
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
                    'help_text' => 'Have at least one active weather monitoring source: a weather radio tuned to the local NWS frequency, an app that receives NWS alerts, or a dedicated NOAA receiver. Designate someone to monitor and announce warnings. Plan a shelter-in-place or evacuation procedure in advance.',
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
                    'help_text' => 'Keep operating positions organized with cables managed and excess coiled. Maintain clear walkways between stations and exits. Store personal gear, coolers, and supplies in designated areas, not in traffic paths.',
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
        ],
        'briefing' => [
            'name' => 'Volunteer Briefing',
            'description' => 'Brief volunteers on the radio plan and event procedures before they take their posts.',
            'scope' => 'event',
            'completion_rule' => 'required_items',
            'bonus_code' => null,
            'shift_roles' => [],
            'locked' => false,
            'items' => [
                [
                    'label' => 'Briefing held: assignments, tactical callsigns and net frequencies reviewed',
                    'is_required' => true,
                    'help_text' => 'Walk every volunteer through where they will be, who they report to, their tactical callsign, and the primary and backup frequencies. Hand out or post the ICS-205 communications plan if you have one.',
                ],
                [
                    'label' => 'Radio check completed with net control',
                    'is_required' => true,
                    'help_text' => 'Each position checks in with net control from where it will operate, before the event starts. Fix any coverage gaps now, not when traffic picks up.',
                ],
                [
                    'label' => 'Emergency, medical and EMS procedures shared',
                    'is_required' => true,
                    'help_text' => 'Volunteers know how to report an emergency on the net, who relays it to EMS or the served agency, and what not to say on the air (names, medical details).',
                ],
                [
                    'label' => 'Weather checked and shelter plan shared',
                    'is_required' => true,
                    'help_text' => 'Check the forecast and any NWS alerts. Tell volunteers where to shelter and who calls a weather stand-down.',
                ],
                [
                    'label' => 'Hydration, sun and rest plan reviewed',
                    'is_required' => false,
                    'help_text' => 'Remind volunteers to bring water, sun protection and layers, and how to request a relief or break over the net.',
                ],
                [
                    'label' => 'Check-out procedure explained',
                    'is_required' => false,
                    'help_text' => 'Volunteers check out with net control before leaving their position, so nobody is left unaccounted for at the end of the event.',
                ],
            ],
        ],
        'station_setup' => [
            'name' => 'Station Setup and Radio Check',
            'description' => 'Check each station or post before reporting ready to net control.',
            'scope' => 'station',
            'completion_rule' => 'required_items',
            'bonus_code' => null,
            'shift_roles' => [],
            'locked' => false,
            'items' => [
                [
                    'label' => 'Antenna installed, connected, and checked',
                    'is_required' => true,
                    'help_text' => 'Check feed line connections, mast and guy lines, and SWR on the assigned frequencies.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Radio power checked',
                    'is_required' => true,
                    'help_text' => 'Confirm the radio powers up and the power lead is fused. Record battery voltage below if used.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Battery voltage at setup (V)',
                    'is_required' => false,
                    'help_text' => 'Enter volts before transmitting. Leave blank if not using a battery.',
                    'answer_type' => 'number',
                ],
                [
                    'label' => 'Radio programmed with the event channels',
                    'is_required' => true,
                    'help_text' => 'Check primary and backup frequencies, offsets, and tones against the event radio plan.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Can net control hear you clearly?',
                    'is_required' => true,
                    'help_text' => 'Make the radio check from your assigned post. Add signal reports or coverage problems in the notes.',
                    'answer_type' => 'yes_no_na',
                ],
                [
                    'label' => 'Is a backup radio available?',
                    'is_required' => false,
                    'help_text' => null,
                    'answer_type' => 'yes_no_na',
                ],
                [
                    'label' => 'Station location for net control',
                    'is_required' => false,
                    'help_text' => 'Enter a location net control can use, such as Mile 12 aid station, Elm St and 3rd.',
                    'answer_type' => 'text',
                ],
            ],
        ],
        'go_kit' => [
            'name' => 'Go-Kit',
            'description' => 'Check your radio gear and supplies before leaving for your assignment.',
            'scope' => 'person',
            'completion_rule' => 'required_items',
            'bonus_code' => null,
            'shift_roles' => [],
            'locked' => false,
            'items' => [
                [
                    'label' => 'Primary radio and charged batteries',
                    'is_required' => true,
                    'help_text' => null,
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Spare batteries or power bank',
                    'is_required' => true,
                    'help_text' => null,
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Antenna and adapters',
                    'is_required' => true,
                    'help_text' => 'Pack the antenna for your assignment and the adapters needed to connect it.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Earpiece or headset',
                    'is_required' => false,
                    'help_text' => 'Lets you hear traffic in a noisy spot without disturbing others.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Copy of the radio plan and frequency list',
                    'is_required' => true,
                    'help_text' => 'Paper copy, in case your phone or tablet dies.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Pen, notepad, and message forms',
                    'is_required' => true,
                    'help_text' => 'ICS-213 forms or the served agency\'s message form.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'ID, license copy, and credentials',
                    'is_required' => true,
                    'help_text' => null,
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Water, food, and medications for the shift',
                    'is_required' => true,
                    'help_text' => null,
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Clothing for the weather, including rain gear',
                    'is_required' => false,
                    'help_text' => null,
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Flashlight or headlamp',
                    'is_required' => false,
                    'help_text' => null,
                    'answer_type' => 'checkbox',
                ],
            ],
        ],
        'shelter_eoc_opening' => [
            'name' => 'Shelter or EOC Opening',
            'description' => 'Open a radio position at a shelter, emergency operations center (EOC), or other served-agency facility.',
            'scope' => 'station',
            'completion_rule' => 'required_items',
            'bonus_code' => null,
            'shift_roles' => [],
            'locked' => false,
            'items' => [
                [
                    'label' => 'Signed in with the facility or shelter manager',
                    'is_required' => true,
                    'help_text' => 'Confirm who you report to and where to set up the radio position.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Radio position set up and powered',
                    'is_required' => true,
                    'help_text' => null,
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Antenna type and location',
                    'is_required' => true,
                    'help_text' => 'Record the antenna used and where it is installed. Note any setup problems.',
                    'answer_type' => 'text',
                ],
                [
                    'label' => 'Is commercial power available?',
                    'is_required' => false,
                    'help_text' => null,
                    'answer_type' => 'yes_no_na',
                ],
                [
                    'label' => 'Backup power ready',
                    'is_required' => true,
                    'help_text' => 'Confirm the backup power source is ready for use if commercial power fails.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Check-in with net control completed',
                    'is_required' => true,
                    'help_text' => null,
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Phone and internet status',
                    'is_required' => false,
                    'help_text' => 'Record which services work: landline, cell, and internet. Report outages to net control.',
                    'answer_type' => 'text',
                ],
                [
                    'label' => 'Message forms and log ready',
                    'is_required' => true,
                    'help_text' => null,
                    'answer_type' => 'checkbox',
                ],
            ],
        ],
        'teardown' => [
            'name' => 'Teardown and Check-Out',
            'description' => 'Check out with net control, return equipment, and hand in logs before leaving your post.',
            'scope' => 'station',
            'completion_rule' => 'required_items',
            'bonus_code' => null,
            'shift_roles' => [],
            'locked' => false,
            'items' => [
                [
                    'label' => 'Checked out with net control',
                    'is_required' => true,
                    'help_text' => 'Confirm net control has released your post before leaving.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Borrowed equipment accounted for',
                    'is_required' => true,
                    'help_text' => 'Return borrowed gear to its owner or the equipment return point. Note anything still awaiting return.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Logs and message forms handed in',
                    'is_required' => true,
                    'help_text' => 'Paper logs and messages go to net control or the communications unit leader.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Site left as found',
                    'is_required' => true,
                    'help_text' => 'Remove trash, stakes, and tape. Check for gear left behind.',
                    'answer_type' => 'checkbox',
                ],
                [
                    'label' => 'Problems or damage to report',
                    'is_required' => false,
                    'help_text' => 'Anything net control or the equipment owner should know about.',
                    'answer_type' => 'text',
                ],
            ],
        ],
    ];

    /**
     * Prevent instantiation; this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Templates an event starts with.
     *
     * ARRL 7.3.17 limits the Safety Officer bonus to Class A; 7.3.18 Site
     * Responsibilities covers Classes B through F. Unscored events start with
     * the volunteer briefing instead.
     *
     * @return list<string>
     */
    public static function defaultKeysFor(EventConfiguration $eventConfiguration): array
    {
        if (! $eventConfiguration->isScored()) {
            return ['briefing'];
        }

        $operatingClass = $eventConfiguration->operatingClass;
        $classLetter = $operatingClass ? preg_replace('/\d+/', '', $operatingClass->code) : null;

        return match ($classLetter) {
            'A' => ['arrl_safety_officer'],
            'B', 'C', 'D', 'E', 'F' => ['arrl_site_responsibilities'],
            default => [],
        };
    }

    /**
     * Templates offered when adding a checklist to an event. The ARRL lists
     * are only offered to scored events.
     *
     * @return array<string, string> Template key => name.
     */
    public static function optionsFor(EventConfiguration $eventConfiguration): array
    {
        return collect(self::TEMPLATES)
            ->reject(fn (array $template) => $template['bonus_code'] !== null && ! $eventConfiguration->isScored())
            ->map(fn (array $template) => $template['name'])
            ->all();
    }

    /**
     * Give an event its starting checklists the first time it is opened.
     * An event that has ever had a checklist, even a deleted one, is left alone.
     */
    public static function seedDefaults(EventConfiguration $eventConfiguration): void
    {
        if ($eventConfiguration->checklists()->withTrashed()->exists()) {
            return;
        }

        foreach (self::defaultKeysFor($eventConfiguration) as $key) {
            self::create($eventConfiguration, $key);
        }
    }

    /**
     * Create a checklist on an event from a template.
     */
    public static function create(EventConfiguration $eventConfiguration, string $key): Checklist
    {
        $template = self::TEMPLATES[$key];

        $checklist = $eventConfiguration->checklists()->create([
            'name' => $template['name'],
            'description' => $template['description'],
            'scope' => ChecklistScope::from($template['scope']),
            'completion_rule' => ChecklistCompletionRule::from($template['completion_rule']),
            'bonus_code' => $template['bonus_code'],
            'template_key' => $key,
            'sort_order' => (int) $eventConfiguration->checklists()->max('sort_order') + 1,
        ]);

        foreach ($template['items'] as $index => $item) {
            $checklist->items()->create([
                'label' => $item['label'],
                'help_text' => $item['help_text'],
                'answer_type' => ChecklistAnswerType::from($item['answer_type'] ?? 'checkbox'),
                'is_required' => $item['is_required'],
                'is_default' => $template['locked'],
                'sort_order' => $index,
            ]);
        }

        if ($template['shift_roles'] !== []) {
            // The ARRL lists are limited to their safety role, so make sure the
            // event has its default roles before looking them up.
            if (! $eventConfiguration->shiftRoles()->withTrashed()->exists()) {
                ShiftRole::seedDefaults($eventConfiguration);
            }

            $checklist->shiftRoles()->sync(
                ShiftRole::where('event_configuration_id', $eventConfiguration->id)
                    ->whereIn('name', $template['shift_roles'])
                    ->pluck('id')
            );
        }

        return $checklist;
    }
}
