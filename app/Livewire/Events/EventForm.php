<?php

namespace App\Livewire\Events;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EventForm extends Component
{
    use AuthorizesRequests;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    // Form mode: 'create', 'edit', or 'clone'
    public string $mode = 'create';

    public ?int $eventId = null;

    // Section 1: Event Information
    public ?string $name = null;

    public ?int $event_type_id = null;

    public ?string $start_time = null;

    public ?string $end_time = null;

    public ?int $year = null;

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

    // Section 5: Guestbook Settings
    public bool $guestbook_enabled = false;

    public ?float $guestbook_latitude = null;

    public ?float $guestbook_longitude = null;

    public ?int $guestbook_detection_radius = 500;

    public ?string $guestbook_local_subnets = null;

    // State tracking
    public bool $isLocked = false;

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
        }
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

            // Load guestbook settings
            $this->guestbook_enabled = $this->configuration->guestbook_enabled;
            $this->guestbook_latitude = $this->configuration->guestbook_latitude;
            $this->guestbook_longitude = $this->configuration->guestbook_longitude;
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
        $this->autofillFieldDayDates();
    }

    /**
     * Autofill start/end times if the selected event type is Field Day.
     * Field Day is the 4th full weekend of June: 1800 UTC Saturday to 2059 UTC Sunday.
     */
    private function autofillFieldDayDates(): void
    {
        if (! $this->event_type_id) {
            return;
        }

        $eventType = EventType::find($this->event_type_id);

        if (! $eventType || $eventType->code !== 'FD') {
            return;
        }

        $year = $this->year ?? (int) now()->year;

        // Find the 4th Saturday in June
        $june1 = Carbon::create($year, 6, 1);
        $firstSaturday = $june1->isSaturday() ? $june1->copy() : $june1->copy()->next(Carbon::SATURDAY);
        $fourthSaturday = $firstSaturday->copy()->addWeeks(3);

        $this->start_time = $fourthSaturday->copy()->setTime(18, 0, 0)->format(self::DATETIME_FORMAT);
        $this->end_time = $fourthSaturday->copy()->addDay()->setTime(20, 59, 0)->format(self::DATETIME_FORMAT);
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

    #[Computed]
    public function powerMultiplier(): string
    {
        if ($this->max_power_watts > 100) {
            return '1';
        }

        if ($this->max_power_watts <= 5 && $this->hasQrpNaturalPowerBonus()) {
            return '5';
        }

        // 6-100W or QRP without natural power bonus gets 2x
        return '2';
    }

    /**
     * Check if current power configuration qualifies for QRP natural power bonus (5x).
     */
    protected function hasQrpNaturalPowerBonus(): bool
    {
        $hasNaturalPower = $this->uses_battery
            || $this->uses_solar
            || $this->uses_wind
            || $this->uses_water;

        $hasDisqualifyingPower = $this->uses_commercial_power || $this->uses_generator;

        return $hasNaturalPower && ! $hasDisqualifyingPower;
    }

    #[Computed]
    public function powerMultiplierColor(): string
    {
        return match ($this->powerMultiplier) {
            5 => 'success',
            2 => 'warning',
            1 => 'error',
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
        if ($this->isLocked && in_array($property, [
            'event_type_id',
            'start_time',
            'callsign',
            'operating_class_id',
            'transmitter_count',
            'max_power_watts',
            'uses_commercial_power',
            'uses_generator',
            'uses_battery',
            'uses_solar',
            'uses_wind',
            'uses_water',
            'uses_methane',
            'uses_alternate_power',
            'uses_other_power',
            'has_gota_station',
            'gota_callsign',
        ])) {
            $this->dispatch('notify', title: 'Field Locked', description: 'This field cannot be modified because the event has contacts.');

            // Cancel the update by not allowing it to proceed
            $this->skipRender();
        }
    }

    public function updatedEventTypeId($value): void
    {
        if (! $value || $this->mode === 'edit') {
            return;
        }

        $this->autofillFieldDayDates();
    }

    public function updated($property): void
    {
        // Auto-calculate year from name if it contains a year
        if ($property === 'name' && preg_match('/\d{4}/', $this->name, $matches)) {
            $previousYear = $this->year;
            $this->year = (int) $matches[0];

            // Recalculate Field Day dates when year changes
            if ($this->year !== $previousYear && $this->mode !== 'edit') {
                $this->autofillFieldDayDates();
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
        $validated = $this->validate();

        // STRICT VALIDATION: If editing currently active event, ensure end date doesn't exclude current time
        if ($this->mode === 'edit' && $this->eventId) {
            $isCurrentlyActive = Event::active()->where('id', $this->eventId)->exists();
            if ($isCurrentlyActive) {
                $newEndTime = \Carbon\Carbon::parse($validated['end_time']);

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

        $this->dispatch('notify', title: 'Success', description: 'Event saved successfully.');
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
            'guestbook_enabled' => $validated['guestbook_enabled'] ?? false,
            'guestbook_latitude' => $validated['guestbook_latitude'] ?? null,
            'guestbook_longitude' => $validated['guestbook_longitude'] ?? null,
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

        $oldValues = [
            'name' => $this->event->name,
            'callsign' => $this->configuration->callsign,
        ];

        $eventData = [
            'name' => $validated['name'],
            'year' => $this->year,
            'end_time' => $validated['end_time'],
        ];

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
            'guestbook_enabled' => $validated['guestbook_enabled'] ?? false,
            'guestbook_latitude' => $validated['guestbook_latitude'] ?? null,
            'guestbook_longitude' => $validated['guestbook_longitude'] ?? null,
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
            ...$this->powerRules(),
            ...$this->gotaRules(),
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
                        $fail('At least one power source must be selected.');
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
            'guestbook_latitude' => ['nullable', 'numeric', 'min:-90', 'max:90'],
            'guestbook_longitude' => ['nullable', 'numeric', 'min:-180', 'max:180'],
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
            'name.required' => 'Please provide an event name.',
            'event_type_id.required' => 'Please select an event type.',
            'start_time.required' => 'Please provide a start time.',
            'end_time.required' => 'Please provide an end time.',
            'end_time.after' => 'The end time must be after the start time.',
            'callsign.required' => 'Please provide a callsign.',
            'callsign.regex' => 'The callsign format is invalid. Use only letters, numbers, and forward slashes.',
            'section_id.required' => 'Please select an ARRL/RAC section.',
            'operating_class_id.required' => 'Please select an operating class.',
            'transmitter_count.required' => 'Please specify the number of transmitters.',
            'max_power_watts.required' => 'Please specify the maximum power.',
            'gota_callsign.regex' => 'The GOTA callsign format is invalid. Use only letters, numbers, and forward slashes.',
            'guestbook_latitude.min' => 'Latitude must be between -90 and 90 degrees.',
            'guestbook_latitude.max' => 'Latitude must be between -90 and 90 degrees.',
            'guestbook_longitude.min' => 'Longitude must be between -180 and 180 degrees.',
            'guestbook_longitude.max' => 'Longitude must be between -180 and 180 degrees.',
            'guestbook_detection_radius.min' => 'Detection radius must be at least 100 meters.',
            'guestbook_detection_radius.max' => 'Detection radius cannot exceed 2000 meters.',
        ];
    }

    public function render(): View
    {
        return view('livewire.events.event-form')->layout('layouts.app');
    }
}
