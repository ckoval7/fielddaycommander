<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Events;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Organization;
use App\Models\Section;
use App\Models\Setting;
use App\Scoring\Contracts\FieldDayRuleSet;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\Dto\PowerContext;
use App\Scoring\RuleSetFactory;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EventForm extends Component
{
    use AuthorizesRequests;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Fields that can't change once the event is locked, with the label shown to the user.
     *
     * @var array<string, string>
     */
    private const POWER_SOURCES = 'power sources';

    private const LOCKED_FIELDS = [
        'event_type_id' => 'event type',
        'start_time' => 'start date',
        'callsign' => 'callsign',
        'operating_class_id' => 'operating class',
        'transmitter_count' => 'transmitter count',
        'max_power_watts' => 'maximum power',
        'uses_commercial_power' => self::POWER_SOURCES,
        'uses_generator' => self::POWER_SOURCES,
        'uses_battery' => self::POWER_SOURCES,
        'uses_solar' => self::POWER_SOURCES,
        'uses_wind' => self::POWER_SOURCES,
        'uses_water' => self::POWER_SOURCES,
        'uses_methane' => self::POWER_SOURCES,
        'uses_alternate_power' => self::POWER_SOURCES,
        'uses_other_power' => self::POWER_SOURCES,
        'has_gota_station' => 'GOTA station',
        'gota_callsign' => 'GOTA callsign',
    ];

    // Form mode: 'create', 'edit', or 'clone'
    #[Locked]
    public string $mode = 'create';

    #[Locked]
    public ?int $eventId = null;

    // Section 1: Event Information
    public ?string $name = null;

    public ?int $event_type_id = null;

    public ?string $start_time = null;

    public ?string $end_time = null;

    public ?int $year = null;

    public ?string $rules_version = null;

    // Section 2: Station Configuration
    public ?string $callsign = null;

    public ?string $club_name = null;

    public ?int $section_id = null;

    public ?int $operating_class_id = null;

    public ?int $transmitter_count = 1;

    // Section 3: Power Configuration
    public ?int $max_power_watts = null;

    public bool $uses_commercial_power = false;

    public bool $uses_generator = false;

    public bool $uses_battery = false;

    public bool $uses_solar = false;

    public bool $uses_wind = false;

    public bool $uses_water = false;

    public bool $uses_methane = false;

    public bool $uses_alternate_power = false;

    public ?string $uses_other_power = null;

    // Section 4: GOTA Station
    public bool $has_gota_station = false;

    public ?string $gota_callsign = null;

    // Section 2: Event Location (all optional)
    public ?string $gridSquare = null;

    public ?float $latitude = null;

    public ?float $longitude = null;

    public ?string $city = null;

    public ?string $state = null;

    public ?string $talk_in_frequency = null;

    // Public visitor details (all optional)
    public bool $is_open_to_public = false;

    public ?string $public_directions = null;

    public ?string $public_visiting_hours = null;

    public ?string $public_contact_email = null;

    public ?string $public_contact_phone = null;

    public ?string $public_website_url = null;

    public ?string $public_description = null;

    // Section 5: Guestbook Settings
    public bool $guestbook_enabled = false;

    public ?int $guestbook_detection_radius = 500;

    public ?string $guestbook_local_subnets = null;

    // State tracking
    #[Locked]
    public bool $isLocked = false;

    #[Locked]
    public bool $rulesVersionLocked = false;

    /**
     * Labels of locked fields whose edits were discarded during this save.
     *
     * @var list<string>
     */
    private array $discardedLockedFields = [];

    private ?Event $event = null;

    private ?EventConfiguration $configuration = null;

    public function mount(?int $eventId = null, ?string $mode = null): void
    {
        $this->resolveMode($eventId, $mode);
        $this->authorizeMode();
        $this->initializeEventData();
    }

    /**
     * Detect mode from parameter, route name, or default to create.
     */
    private function resolveMode(?int $eventId, ?string $mode): void
    {
        if ($eventId) {
            $this->eventId = $eventId;
        }

        if ($mode) {
            $this->mode = $mode;
        } elseif ($eventId) {
            $routeName = request()->route()?->getName();
            $this->mode = $routeName === 'events.clone' ? 'clone' : 'edit';
        } else {
            $this->mode = 'create';
        }
    }

    /**
     * Authorize the current mode.
     */
    private function authorizeMode(): void
    {
        match ($this->mode) {
            'create', 'clone' => $this->authorize('create-events'),
            'edit' => $this->authorize('edit-events'),
            default => null,
        };
    }

    /**
     * Load event data or set defaults based on the current mode.
     */
    private function initializeEventData(): void
    {
        if ($this->eventId && ($this->mode === 'edit' || $this->mode === 'clone')) {
            $this->loadEvent();

            if ($this->mode === 'clone') {
                $this->prepareClone();
            }
        }

        if ($this->mode === 'create') {
            $this->year = now()->year;
            $this->prefillFromOrganization();
        }

        $this->defaultRulesVersionIfUnset();
    }

    private function defaultRulesVersionIfUnset(): void
    {
        if ($this->rules_version !== null || $this->year === null) {
            return;
        }

        $versions = $this->availableRulesVersions();

        if ($versions === []) {
            return;
        }

        $default = (string) $this->year;
        $fallback = end($versions);

        $this->rules_version = in_array($default, $versions, true) ? $default : $fallback;
    }

    /**
     * Registered rules versions for the selected event type.
     *
     * @return array<int, string>
     */
    protected function availableRulesVersions(): array
    {
        if (! $this->event_type_id) {
            return [];
        }

        $code = EventType::query()->whereKey($this->event_type_id)->value('code');

        return $code ? app(RuleSetFactory::class)->versionsFor($code) : [];
    }

    #[Computed]
    public function rulesVersionOptions(): array
    {
        $versions = $this->availableRulesVersions();

        $options = array_map(fn (string $v) => ['id' => $v, 'name' => $v], $versions);

        if ($this->rules_version !== null && ! in_array($this->rules_version, $versions, true)) {
            array_unshift($options, ['id' => $this->rules_version, 'name' => $this->rules_version.' (not registered)']);
        }

        return $options;
    }

    private function prefillFromOrganization(): void
    {
        $organizationId = Setting::get('default_organization_id');

        if (! $organizationId) {
            return;
        }

        $organization = Organization::find($organizationId);

        if (! $organization) {
            return;
        }

        $this->callsign = $organization->callsign;
        $this->club_name = $organization->name;
    }

    private function loadEvent(): void
    {
        $this->event = Event::with('eventConfiguration', 'eventType')->findOrFail($this->eventId);
        $this->configuration = $this->event->eventConfiguration;

        // Load event fields
        $this->name = $this->event->name;
        $this->event_type_id = $this->event->event_type_id;
        $this->start_time = $this->event->start_time?->format(self::DATETIME_FORMAT);
        $this->end_time = $this->event->end_time?->format(self::DATETIME_FORMAT);
        $this->year = $this->event->year;
        $this->rules_version = $this->event->rules_version;
        $this->rulesVersionLocked = $this->event->start_time !== null
            && $this->event->start_time <= appNow();

        // Load configuration fields if they exist
        if ($this->configuration) {
            $this->callsign = $this->configuration->callsign;
            $this->club_name = $this->configuration->club_name;
            $this->section_id = $this->configuration->section_id;
            $this->operating_class_id = $this->configuration->operating_class_id;
            $this->transmitter_count = $this->configuration->transmitter_count;
            $this->max_power_watts = $this->configuration->max_power_watts;
            $this->uses_commercial_power = $this->configuration->uses_commercial_power;
            $this->uses_generator = $this->configuration->uses_generator;
            $this->uses_battery = $this->configuration->uses_battery;
            $this->uses_solar = $this->configuration->uses_solar;
            $this->uses_wind = $this->configuration->uses_wind;
            $this->uses_water = $this->configuration->uses_water;
            $this->uses_methane = $this->configuration->uses_methane;
            $this->uses_alternate_power = $this->configuration->uses_solar
                || $this->configuration->uses_wind
                || $this->configuration->uses_water
                || $this->configuration->uses_methane;
            $this->uses_other_power = $this->configuration->uses_other_power;
            $this->has_gota_station = $this->configuration->has_gota_station;
            $this->gota_callsign = $this->configuration->gota_callsign;

            // Load location fields
            $this->gridSquare = $this->configuration->grid_square;
            $this->latitude = $this->configuration->latitude;
            $this->longitude = $this->configuration->longitude;
            $this->city = $this->configuration->city;
            $this->state = $this->configuration->state;
            $this->talk_in_frequency = $this->configuration->talk_in_frequency;

            $this->is_open_to_public = (bool) $this->configuration->is_open_to_public;
            $this->public_directions = $this->configuration->public_directions;
            $this->public_visiting_hours = $this->configuration->public_visiting_hours;
            $this->public_contact_email = $this->configuration->public_contact_email;
            $this->public_contact_phone = $this->configuration->public_contact_phone;
            $this->public_website_url = $this->configuration->public_website_url;
            $this->public_description = $this->configuration->public_description;

            // Load guestbook settings
            $this->guestbook_enabled = $this->configuration->guestbook_enabled;
            $this->guestbook_detection_radius = $this->configuration->guestbook_detection_radius ?? 500;
            // Convert array to string for textarea (one CIDR per line)
            $this->guestbook_local_subnets = is_array($this->configuration->guestbook_local_subnets)
                ? implode("\n", $this->configuration->guestbook_local_subnets)
                : $this->configuration->guestbook_local_subnets;

            // Check if locked
            $this->isLocked = $this->configuration->isLocked();
        }
    }

    private function prepareClone(): void
    {
        // Increment year in name (e.g., "Field Day 2025" -> "Field Day 2026")
        if ($this->name && preg_match('/\d{4}/', $this->name, $matches)) {
            $oldYear = (int) $matches[0];
            $newYear = $oldYear + 1;
            $this->name = str_replace((string) $oldYear, (string) $newYear, $this->name);
            $this->year = $newYear;
        }

        // Reset event ID so we create a new one
        $this->eventId = null;

        // Not locked for clone
        $this->isLocked = false;

        // Clear dates, then autofill if Field Day
        $this->start_time = null;
        $this->end_time = null;
        $this->autofillEventDates();
    }

    /**
     * Published operating window for each event type we can prefill.
     *
     * Keyed by event type code. Each entry gives the month whose 4th full
     * weekend the event falls on, the UTC start time on the Saturday, and the
     * UTC end time on the Sunday.
     *
     *  - FD:  ARRL Field Day, 4th full weekend of June, 1800Z Sat to 2059Z Sun.
     *  - WFD: Winter Field Day, 4th full weekend of January, 1600Z Sat to
     *         2159Z Sun (the WFDA SOP's 30-hour operational period).
     *
     * @var array<string, array{month: int, start: array{int, int}, end: array{int, int}}>
     */
    private const OPERATING_WINDOWS = [
        'FD' => ['month' => 6, 'start' => [18, 0], 'end' => [20, 59]],
        'WFD' => ['month' => 1, 'start' => [16, 0], 'end' => [21, 59]],
    ];

    /**
     * Autofill start/end times from the selected event type's published window.
     *
     * No-op for event types with no published window of their own.
     */
    private function autofillEventDates(): void
    {
        if (! $this->event_type_id) {
            return;
        }

        $eventType = EventType::find($this->event_type_id);
        $window = self::OPERATING_WINDOWS[$eventType?->code] ?? null;

        if (! $window) {
            return;
        }

        $year = $this->year ?? (int) now()->year;
        $saturday = $this->fourthFullWeekendSaturday($year, $window['month']);

        $this->start_time = $saturday->copy()
            ->setTime($window['start'][0], $window['start'][1], 0)
            ->format(self::DATETIME_FORMAT);

        $this->end_time = $saturday->copy()
            ->addDay()
            ->setTime($window['end'][0], $window['end'][1], 0)
            ->format(self::DATETIME_FORMAT);
    }

    /**
     * The Saturday of the 4th full weekend in the given month.
     */
    private function fourthFullWeekendSaturday(int $year, int $month): Carbon
    {
        $firstOfMonth = Carbon::create($year, $month, 1);

        $firstSaturday = $firstOfMonth->isSaturday()
            ? $firstOfMonth->copy()
            : $firstOfMonth->copy()->next(Carbon::SATURDAY);

        return $firstSaturday->copy()->addWeeks(3);
    }

    /**
     * Computed setup window start time for display in the form.
     * Only applies to Field Day events with a start time set.
     */
    #[Computed]
    public function setupAllowedFrom(): ?string
    {
        if (! $this->start_time || ! $this->event_type_id) {
            return null;
        }

        $eventType = EventType::find($this->event_type_id);

        if (! $eventType || $eventType->setup_offset_hours === null) {
            return null;
        }

        return Event::calculateSetupAllowedFrom(Carbon::parse($this->start_time), $eventType->setup_offset_hours)
            ->format('l, F j, Y \a\t Hi\z');
    }

    /**
     * Whether the selected event type's rulebook scores with a power multiplier.
     *
     * Winter Field Day caps every station at 100 W PEP and has no multiplier,
     * so showing one on the form would misstate how the event is scored.
     */
    #[Computed]
    public function usesPowerMultiplier(): bool
    {
        $rules = $this->selectedRuleSet();

        return $rules === null || $rules instanceof FieldDayRuleSet;
    }

    /**
     * The ruleset the event would be scored under with the form's current
     * event type and rules version, or null when neither resolves one.
     */
    protected function selectedRuleSet(): ?RuleSet
    {
        $code = $this->event_type_id
            ? EventType::query()->whereKey($this->event_type_id)->value('code')
            : null;

        $versions = $code ? app(RuleSetFactory::class)->versionsFor($code) : [];

        if ($code === null || $versions === []) {
            return null;
        }

        return app(RuleSetFactory::class)->resolve($code, $this->rules_version ?? end($versions));
    }

    /**
     * Power multiplier preview, computed by the selected rules version.
     *
     * Uses the same effective power and natural-power checks as scoring. When
     * editing a saved event, its stations count too. A new or cloned event has
     * no stations yet, so the preview reflects the event-level settings only.
     */
    #[Computed]
    public function powerMultiplier(): string
    {
        // Before an event type is picked, preview with the newest Field Day rules.
        $rules = $this->selectedRuleSet() ?? app(RuleSetFactory::class)->resolve('FD', (string) $this->year);

        if (! $rules instanceof FieldDayRuleSet) {
            return '1';
        }

        $config = $this->previewConfiguration();

        return $rules->powerMultiplier(new PowerContext(
            effectivePowerWatts: $config->effectiveMaxPowerWatts(),
            qualifiesForQrpNaturalBonus: $config->hasQrpNaturalPowerBonus(),
        ));
    }

    /**
     * An unsaved configuration carrying the form's power settings.
     *
     * When editing, it is the saved configuration with the form's values laid
     * over it in memory, so its existing stations are included in the checks.
     */
    protected function previewConfiguration(): EventConfiguration
    {
        $config = $this->mode === 'edit' && $this->eventId
            ? EventConfiguration::query()->where('event_id', $this->eventId)->first()
            : null;

        return ($config ?? new EventConfiguration)->fill([
            'max_power_watts' => (int) $this->max_power_watts,
            'uses_commercial_power' => $this->uses_commercial_power,
            'uses_generator' => $this->uses_generator,
            'uses_battery' => $this->uses_battery,
            'uses_solar' => $this->uses_solar,
            'uses_wind' => $this->uses_wind,
            'uses_water' => $this->uses_water,
            'uses_methane' => $this->uses_methane,
        ]);
    }

    #[Computed]
    public function powerMultiplierColor(): string
    {
        return match ($this->powerMultiplier) {
            '5' => 'success',
            '2' => 'warning',
            '1' => 'error',
            default => 'neutral',
        };
    }

    #[Computed]
    public function eventTypes()
    {
        return EventType::where('is_active', true)->get();
    }

    #[Computed]
    public function sections()
    {
        return Section::where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function operatingClasses()
    {
        if (! $this->event_type_id) {
            return collect();
        }

        return OperatingClass::where('event_type_id', $this->event_type_id)->get();
    }

    /**
     * Whether the selected event type is Winter Field Day.
     */
    #[Computed]
    public function isWinterFieldDay(): bool
    {
        if (! $this->event_type_id) {
            return false;
        }

        return EventType::query()->whereKey($this->event_type_id)->value('code') === 'WFD';
    }

    #[Computed]
    public function selectedOperatingClass(): ?OperatingClass
    {
        if (! $this->operating_class_id) {
            return null;
        }

        return OperatingClass::find($this->operating_class_id);
    }

    #[Computed]
    public function allowsGota(): bool
    {
        return $this->selectedOperatingClass?->allows_gota ?? false;
    }

    #[Computed]
    public function maxPowerLimit(): ?int
    {
        return $this->selectedOperatingClass?->max_power_watts;
    }

    public function updating($property, $value): void
    {
        // Prevent updates to locked fields BEFORE the value changes
        if ($this->isLocked && array_key_exists($property, self::LOCKED_FIELDS)) {
            $this->dispatch('notify', title: 'Field Locked', description: 'This field is locked because the event has contacts or has started.');

            // Cancel the update by not allowing it to proceed
            $this->skipRender();
        }
    }

    public function updatedEventTypeId($value): void
    {
        if (! $value || $this->mode === 'edit') {
            return;
        }

        $this->autofillEventDates();
        $this->rules_version = null;
        $this->defaultRulesVersionIfUnset();
    }

    public function updated($property): void
    {
        // Auto-calculate year from name if it contains a year
        if ($property === 'name' && preg_match('/\d{4}/', $this->name, $matches)) {
            $previousYear = $this->year;
            $this->year = (int) $matches[0];

            // Recalculate Field Day dates when year changes
            if ($this->year !== $previousYear && $this->mode !== 'edit') {
                $this->autofillEventDates();
            }
        }

        // Clear GOTA fields if class doesn't allow GOTA
        if ($property === 'operating_class_id' && ! $this->allowsGota) {
            $this->has_gota_station = false;
            $this->gota_callsign = null;
        }

        // Reset power sources when changing operating class if requires emergency power
        if ($property === 'operating_class_id') {
            $operatingClass = $this->selectedOperatingClass;
            if ($operatingClass && $operatingClass->requires_emergency_power) {
                $this->uses_commercial_power = false;
            }
        }

        // Sync alternate power checkbox with individual fields
        if ($property === 'uses_alternate_power') {
            $this->uses_solar = $this->uses_alternate_power;
            $this->uses_wind = $this->uses_alternate_power;
            $this->uses_water = $this->uses_alternate_power;
            $this->uses_methane = $this->uses_alternate_power;
        }
    }

    public function save(): void
    {
        $this->authorizeMode();

        $validated = $this->validate();

        // STRICT VALIDATION: If editing currently active event, ensure end date doesn't exclude current time
        if ($this->mode === 'edit' && $this->eventId) {
            $isCurrentlyActive = Event::active()->where('id', $this->eventId)->exists();
            if ($isCurrentlyActive) {
                $newEndTime = Carbon::parse($validated['end_time']);

                if (appNow() > $newEndTime) {
                    $this->addError('end_time', 'Cannot set the end date before the current time on an active event.');

                    return;
                }
            }
        }

        DB::transaction(function () use ($validated) {
            if ($this->mode === 'edit' && $this->eventId) {
                $this->updateEvent($validated);
            } else {
                $this->createEvent($validated);
            }
        });

        if ($this->discardedLockedFields !== []) {
            $this->dispatch('toast',
                type: 'warning',
                title: 'Some changes weren\'t saved',
                description: 'Contacts were logged or the event started while you were editing, so these fields are now locked and weren\'t changed: '
                    .implode(', ', $this->discardedLockedFields).'. Your other changes were saved.',
                icon: 'phosphor-lock',
                timeout: 10000,
            );

            return;
        }

        session()->flash('toast', ['title' => 'Success', 'description' => 'Event saved.', 'type' => 'success']);
        $this->redirect(route('events.index'), navigate: true);
    }

    private function createEvent(array $validated): void
    {
        $startTime = Carbon::parse($validated['start_time']);
        $eventType = EventType::find($validated['event_type_id']);

        $event = Event::create([
            'name' => $validated['name'],
            'event_type_id' => $validated['event_type_id'],
            'year' => $this->year,
            'rules_version' => $validated['rules_version'] ?? $this->rules_version,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'setup_allowed_from' => $eventType?->setup_offset_hours !== null
                ? Event::calculateSetupAllowedFrom($startTime, $eventType->setup_offset_hours)
                : null,
            'is_active' => true,
        ]);

        // Convert subnets string to array
        $subnets = null;
        if (! empty($validated['guestbook_local_subnets'])) {
            $subnets = array_filter(
                array_map('trim', explode("\n", $validated['guestbook_local_subnets'])),
                fn ($line) => ! empty($line)
            );
        }

        EventConfiguration::create([
            'event_id' => $event->id,
            'created_by_user_id' => auth()->id(),
            'callsign' => $validated['callsign'],
            'club_name' => $validated['club_name'] ?? null,
            'section_id' => $validated['section_id'],
            'operating_class_id' => $validated['operating_class_id'],
            'transmitter_count' => $validated['transmitter_count'],
            'has_gota_station' => $validated['has_gota_station'] ?? false,
            'gota_callsign' => $validated['gota_callsign'] ?? null,
            'max_power_watts' => $validated['max_power_watts'],
            'power_multiplier' => $this->powerMultiplier,
            'uses_commercial_power' => $validated['uses_commercial_power'] ?? false,
            'uses_generator' => $validated['uses_generator'] ?? false,
            'uses_battery' => $validated['uses_battery'] ?? false,
            'uses_solar' => $validated['uses_solar'] ?? false,
            'uses_wind' => $validated['uses_wind'] ?? false,
            'uses_water' => $validated['uses_water'] ?? false,
            'uses_methane' => $validated['uses_methane'] ?? false,
            'uses_other_power' => $validated['uses_other_power'] ?? null,
            'grid_square' => $validated['gridSquare'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'talk_in_frequency' => $validated['talk_in_frequency'] ?? null,
            'is_open_to_public' => $validated['is_open_to_public'] ?? false,
            'public_directions' => $validated['public_directions'] ?? null,
            'public_visiting_hours' => $validated['public_visiting_hours'] ?? null,
            'public_contact_email' => $validated['public_contact_email'] ?? null,
            'public_contact_phone' => $validated['public_contact_phone'] ?? null,
            'public_website_url' => $validated['public_website_url'] ?? null,
            'public_description' => $validated['public_description'] ?? null,
            'guestbook_enabled' => $validated['guestbook_enabled'] ?? false,
            'guestbook_detection_radius' => $validated['guestbook_detection_radius'] ?? 500,
            'guestbook_local_subnets' => $subnets,
        ]);

        AuditLog::log('event.created', newValues: [
            'name' => $event->name,
            'year' => $event->year,
            'callsign' => $validated['callsign'],
        ], auditable: $event);
    }

    private function updateEvent(array $validated): void
    {
        // Reload event and configuration if not already loaded
        if (! $this->event || ! $this->configuration) {
            $this->event = Event::with('eventConfiguration')->findOrFail($this->eventId);
            $this->configuration = $this->event->eventConfiguration;
        }

        // Recompute lock state from the database; contacts may have been
        // logged since the form was opened.
        $wasLocked = $this->isLocked;
        $this->isLocked = $this->configuration?->isLocked() ?? false;
        $this->rulesVersionLocked = $this->event->start_time !== null
            && $this->event->start_time <= appNow();

        if ($this->isLocked && ! $wasLocked) {
            $this->discardedLockedFields = $this->restoreLockedFields();
        }

        $oldValues = [
            'name' => $this->event->name,
            'callsign' => $this->configuration->callsign,
        ];

        $eventData = [
            'name' => $validated['name'],
            'year' => $this->year,
            'end_time' => $validated['end_time'],
        ];

        if (! $this->rulesVersionLocked && ! empty($validated['rules_version'])) {
            $eventData['rules_version'] = $validated['rules_version'];
        }

        if (! $this->isLocked) {
            $eventData['event_type_id'] = $validated['event_type_id'];
            $eventData['start_time'] = $validated['start_time'];

            $eventType = EventType::find($validated['event_type_id']);
            $eventData['setup_allowed_from'] = $eventType?->setup_offset_hours !== null
                ? Event::calculateSetupAllowedFrom(Carbon::parse($validated['start_time']), $eventType->setup_offset_hours)
                : null;
        }

        $this->event->update($eventData);

        // Convert subnets string to array
        $subnets = null;
        if (! empty($validated['guestbook_local_subnets'])) {
            $subnets = array_filter(
                array_map('trim', explode("\n", $validated['guestbook_local_subnets'])),
                fn ($line) => ! empty($line)
            );
        }

        $configData = [
            'club_name' => $validated['club_name'] ?? null,
            'section_id' => $validated['section_id'],
            'power_multiplier' => $this->powerMultiplier,
            'grid_square' => $validated['gridSquare'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'talk_in_frequency' => $validated['talk_in_frequency'] ?? null,
            'is_open_to_public' => $validated['is_open_to_public'] ?? false,
            'public_directions' => $validated['public_directions'] ?? null,
            'public_visiting_hours' => $validated['public_visiting_hours'] ?? null,
            'public_contact_email' => $validated['public_contact_email'] ?? null,
            'public_contact_phone' => $validated['public_contact_phone'] ?? null,
            'public_website_url' => $validated['public_website_url'] ?? null,
            'public_description' => $validated['public_description'] ?? null,
            'guestbook_enabled' => $validated['guestbook_enabled'] ?? false,
            'guestbook_detection_radius' => $validated['guestbook_detection_radius'] ?? 500,
            'guestbook_local_subnets' => $subnets,
        ];

        // Only update locked fields if not locked
        if (! $this->isLocked) {
            $configData = array_merge($configData, [
                'callsign' => $validated['callsign'],
                'operating_class_id' => $validated['operating_class_id'],
                'transmitter_count' => $validated['transmitter_count'],
                'has_gota_station' => $validated['has_gota_station'] ?? false,
                'gota_callsign' => $validated['gota_callsign'] ?? null,
                'max_power_watts' => $validated['max_power_watts'],
                'uses_commercial_power' => $validated['uses_commercial_power'] ?? false,
                'uses_generator' => $validated['uses_generator'] ?? false,
                'uses_battery' => $validated['uses_battery'] ?? false,
                'uses_solar' => $validated['uses_solar'] ?? false,
                'uses_wind' => $validated['uses_wind'] ?? false,
                'uses_water' => $validated['uses_water'] ?? false,
                'uses_methane' => $validated['uses_methane'] ?? false,
                'uses_other_power' => $validated['uses_other_power'] ?? null,
            ]);
        }

        $this->configuration->update($configData);

        AuditLog::log('event.updated', oldValues: $oldValues, newValues: [
            'name' => $validated['name'],
            'callsign' => $validated['callsign'],
        ], auditable: $this->event);
    }

    /**
     * Put the stored values back into the locked fields so the form shows what
     * was actually saved (and the power multiplier is computed from it).
     *
     * @return list<string> labels of the fields whose edits were discarded
     */
    private function restoreLockedFields(): array
    {
        $stored = [
            'event_type_id' => $this->event->event_type_id,
            'start_time' => $this->event->start_time?->format(self::DATETIME_FORMAT),
            'callsign' => $this->configuration->callsign,
            'operating_class_id' => $this->configuration->operating_class_id,
            'transmitter_count' => $this->configuration->transmitter_count,
            'max_power_watts' => $this->configuration->max_power_watts,
            'uses_commercial_power' => $this->configuration->uses_commercial_power,
            'uses_generator' => $this->configuration->uses_generator,
            'uses_battery' => $this->configuration->uses_battery,
            'uses_solar' => $this->configuration->uses_solar,
            'uses_wind' => $this->configuration->uses_wind,
            'uses_water' => $this->configuration->uses_water,
            'uses_methane' => $this->configuration->uses_methane,
            'uses_other_power' => $this->configuration->uses_other_power,
            'has_gota_station' => $this->configuration->has_gota_station,
            'gota_callsign' => $this->configuration->gota_callsign,
        ];

        $discarded = [];

        foreach ($stored as $property => $value) {
            // Loose comparison on purpose: form values arrive as strings, stored ones are cast.
            if ($this->{$property} != $value) {
                $discarded[] = self::LOCKED_FIELDS[$property];
            }

            $this->{$property} = $value;
        }

        $this->uses_alternate_power = $this->uses_solar || $this->uses_wind || $this->uses_water || $this->uses_methane;

        return array_values(array_unique($discarded));
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'event_type_id' => ['required', 'exists:event_types,id'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'callsign' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9\/]+$/i'],
            'club_name' => ['nullable', 'string', 'max:255'],
            'section_id' => ['required', 'exists:sections,id'],
            'operating_class_id' => ['required', 'exists:operating_classes,id'],
            'transmitter_count' => ['required', 'integer', 'min:1', 'max:99'],
            'rules_version' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9._-]+$/'],
            ...$this->powerRules(),
            ...$this->gotaRules(),
            ...$this->locationRules(),
            ...$this->publicVisitorRules(),
            ...$this->guestbookRules(),
        ];
    }

    /**
     * Validation rules for power-related fields.
     *
     * @return array<string, array<int, mixed>>
     */
    private function powerRules(): array
    {
        return [
            'max_power_watts' => [
                'required',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) {
                    $maxLimit = $this->maxPowerLimit;
                    if ($maxLimit && $value > $maxLimit) {
                        $fail("The power cannot exceed {$maxLimit}W for the selected operating class.");
                    }
                },
            ],
            'uses_commercial_power' => [
                'boolean',
                function ($attribute, $value, $fail) {
                    if (! $this->hasAnyPowerSourceSelected()) {
                        $fail('Select at least one power source.');
                    }
                },
            ],
            'uses_generator' => ['boolean'],
            'uses_battery' => ['boolean'],
            'uses_solar' => ['boolean'],
            'uses_wind' => ['boolean'],
            'uses_water' => ['boolean'],
            'uses_methane' => ['boolean'],
            'uses_alternate_power' => ['boolean'],
            'uses_other_power' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Check if any power source is currently selected.
     */
    private function hasAnyPowerSourceSelected(): bool
    {
        return $this->uses_commercial_power
            || $this->uses_generator
            || $this->uses_battery
            || $this->uses_solar
            || $this->uses_wind
            || $this->uses_water
            || $this->uses_methane
            || $this->uses_other_power;
    }

    /**
     * Validation rules for GOTA-related fields.
     *
     * @return array<string, array<int, mixed>>
     */
    private function gotaRules(): array
    {
        return [
            'has_gota_station' => [
                'boolean',
                function ($attribute, $value, $fail) {
                    if ($value && ! $this->allowsGota) {
                        $fail('The selected operating class does not allow a GOTA station.');
                    }
                },
            ],
            'gota_callsign' => [
                Rule::requiredIf($this->has_gota_station),
                'nullable',
                'string',
                'max:20',
                'regex:/^[A-Z0-9\/]+$/i',
            ],
        ];
    }

    /**
     * Validation rules for location fields.
     *
     * @return array<string, array<int, mixed>>
     */
    private function locationRules(): array
    {
        return [
            'gridSquare' => ['nullable', 'string', 'max:6', 'regex:/^[A-R]{2}[0-9]{2}([A-X]{2})?$/i'],
            'latitude' => ['nullable', 'numeric', 'min:-90', 'max:90'],
            'longitude' => ['nullable', 'numeric', 'min:-180', 'max:180'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2', 'alpha'],
            'talk_in_frequency' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Validation rules for the optional public visitor details.
     *
     * @return array<string, array<int, mixed>>
     */
    private function publicVisitorRules(): array
    {
        return [
            'is_open_to_public' => ['boolean'],
            'public_directions' => ['nullable', 'string', 'max:1000'],
            'public_visiting_hours' => ['nullable', 'string', 'max:500'],
            'public_contact_email' => ['nullable', 'email', 'max:255'],
            'public_contact_phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9\s+\-().]*(?:ext\.?\s*[0-9]+)?$/i'],
            'public_website_url' => ['nullable', 'url:http,https', 'max:255'],
            'public_description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Validation rules for guestbook-related fields.
     *
     * @return array<string, array<int, mixed>>
     */
    private function guestbookRules(): array
    {
        return [
            'guestbook_enabled' => ['boolean'],
            'guestbook_detection_radius' => ['nullable', 'integer', 'min:100', 'max:2000'],
            'guestbook_local_subnets' => [
                'nullable',
                'string',
                fn ($attribute, $value, $fail) => $this->validateSubnets($value, $fail),
            ],
        ];
    }

    /**
     * Validate CIDR subnet notation lines.
     */
    private function validateSubnets(?string $value, \Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $lines = array_filter(
            array_map('trim', explode("\n", $value)),
            fn ($line) => ! empty($line)
        );

        foreach ($lines as $line) {
            if (! preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $line)) {
                $fail("The line '{$line}' is not a valid CIDR notation. Expected format: 192.168.1.0/24");

                return;
            }

            [$ip, $prefix] = explode('/', $line);

            foreach (explode('.', $ip) as $octet) {
                if ((int) $octet > 255) {
                    $fail("The line '{$line}' contains an invalid IP address.");

                    return;
                }
            }

            if ((int) $prefix > 32) {
                $fail("The line '{$line}' contains an invalid prefix length. Must be 0-32.");

                return;
            }
        }
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Please enter an event name.',
            'event_type_id.required' => 'Please select an event type.',
            'start_time.required' => 'Please enter a start time.',
            'end_time.required' => 'Please enter an end time.',
            'end_time.after' => 'The end time must be after the start time.',
            'callsign.required' => 'Please enter a callsign.',
            'callsign.regex' => 'The callsign format is invalid. Use only letters, numbers, and forward slashes.',
            'section_id.required' => 'Please select an ARRL/RAC section.',
            'operating_class_id.required' => 'Please select an operating class.',
            'transmitter_count.required' => 'Please enter the number of transmitters.',
            'max_power_watts.required' => 'Please enter the maximum power.',
            'gota_callsign.regex' => 'The GOTA callsign format is invalid. Use only letters, numbers, and forward slashes.',
            'gridSquare.regex' => 'Grid square must be a valid Maidenhead locator (e.g. DM79 or DM79ab).',
            'latitude.min' => 'Latitude must be between -90 and 90 degrees.',
            'latitude.max' => 'Latitude must be between -90 and 90 degrees.',
            'longitude.min' => 'Longitude must be between -180 and 180 degrees.',
            'longitude.max' => 'Longitude must be between -180 and 180 degrees.',
            'state.size' => 'State / Province must be a 2-letter abbreviation (e.g. MD, CA).',
            'state.alpha' => 'State / Province must be a 2-letter abbreviation (e.g. MD, CA).',
            'guestbook_detection_radius.min' => 'Detection radius must be at least 100 meters.',
            'guestbook_detection_radius.max' => 'Detection radius cannot exceed 2000 meters.',
        ];
    }

    public function render(): View
    {
        return view('livewire.events.event-form')->layout('layouts.app');
    }

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'event_type_id' => 'event type',
            'section_id' => 'section',
            'operating_class_id' => 'operating class',
            'max_power_watts' => 'maximum power',
            'gota_callsign' => 'GOTA callsign',
            'uses_other_power' => 'other power source',
            'talk_in_frequency' => 'talk-in frequency',
            'public_directions' => 'directions',
            'public_visiting_hours' => 'visiting hours',
            'public_contact_email' => 'contact email',
            'public_contact_phone' => 'contact phone',
            'public_website_url' => 'website',
            'public_description' => 'public description',
            'guestbook_detection_radius' => 'detection radius',
        ];
    }
}
