<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EventForm extends Component
{
    use AuthorizesRequests;

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

    public ?string $uses_other_power = null;

    // Section 4: GOTA Station
    public bool $has_gota_station = false;

    public ?string $gota_callsign = null;

    // State tracking
    public bool $isLocked = false;

    private ?Event $event = null;

    private ?EventConfiguration $configuration = null;

    public function mount(?int $eventId = null, ?string $mode = null): void
    {
        // Detect mode - prioritize parameter, then route, then default
        if ($mode) {
            $this->mode = $mode;
        } elseif ($eventId) {
            $this->eventId = $eventId;

            // Determine if we're cloning or editing based on route name
            $routeName = request()->route()?->getName();
            if ($routeName === 'events.clone') {
                $this->mode = 'clone';
            } else {
                $this->mode = 'edit';
            }
        } else {
            $this->mode = 'create';
        }

        // Set eventId if provided
        if ($eventId) {
            $this->eventId = $eventId;
        }

        // Authorization
        if ($this->mode === 'create' || $this->mode === 'clone') {
            $this->authorize('create-events');
        } elseif ($this->mode === 'edit') {
            $this->authorize('edit-events');
        }

        // Load existing event data
        if ($this->eventId && ($this->mode === 'edit' || $this->mode === 'clone')) {
            $this->loadEvent();

            if ($this->mode === 'clone') {
                $this->prepareClone();
            }
        }

        // Set default year for new events
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
        $this->start_time = $this->event->start_time?->format('Y-m-d H:i:s');
        $this->end_time = $this->event->end_time?->format('Y-m-d H:i:s');
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
            $this->uses_other_power = $this->configuration->uses_other_power;
            $this->has_gota_station = $this->configuration->has_gota_station;
            $this->gota_callsign = $this->configuration->gota_callsign;

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

        // Clear dates
        $this->start_time = null;
        $this->end_time = null;

        // Reset event ID so we create a new one
        $this->eventId = null;

        // Not locked for clone
        $this->isLocked = false;
    }

    #[Computed]
    public function powerMultiplier(): int
    {
        // Over 100W always gets 1x
        if ($this->max_power_watts > 100) {
            return 1;
        }

        // 5W or less qualifies for potential 5x
        if ($this->max_power_watts <= 5) {
            // Check for natural power sources
            $hasNaturalPower = $this->uses_battery
                || $this->uses_solar
                || $this->uses_wind
                || $this->uses_water;

            // Check for disqualifying power sources
            $hasDisqualifyingPower = $this->uses_commercial_power || $this->uses_generator;

            // 5x if natural power and no commercial/generator
            if ($hasNaturalPower && ! $hasDisqualifyingPower) {
                return 5;
            }

            // Otherwise QRP with commercial/generator gets 2x
            return 2;
        }

        // 6-100W always gets 2x
        return 2;
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
            'uses_other_power',
            'gota_callsign',
        ])) {
            $this->dispatch('notify', title: 'Field Locked', description: 'This field cannot be modified because the event has contacts.');

            // Cancel the update by not allowing it to proceed
            $this->skipRender();

            return;
        }
    }

    public function updated($property): void
    {
        // Auto-calculate year from name if it contains a year
        if ($property === 'name' && preg_match('/\d{4}/', $this->name, $matches)) {
            $this->year = (int) $matches[0];
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
    }

    public function save(): void
    {
        $validated = $this->validate();

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
        $event = Event::create([
            'name' => $validated['name'],
            'event_type_id' => $validated['event_type_id'],
            'year' => $this->year,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'is_active' => true,
        ]);

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
        ]);
    }

    private function updateEvent(array $validated): void
    {
        // Reload event and configuration if not already loaded
        if (! $this->event || ! $this->configuration) {
            $this->event = Event::with('eventConfiguration')->findOrFail($this->eventId);
            $this->configuration = $this->event->eventConfiguration;
        }

        $this->event->update([
            'name' => $validated['name'],
            'event_type_id' => $validated['event_type_id'],
            'year' => $this->year,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
        ]);

        $configData = [
            'callsign' => $validated['callsign'],
            'club_name' => $validated['club_name'] ?? null,
            'section_id' => $validated['section_id'],
            'power_multiplier' => $this->powerMultiplier,
        ];

        // Only update locked fields if not locked
        if (! $this->isLocked) {
            $configData = array_merge($configData, [
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
            'uses_commercial_power' => ['boolean'],
            'uses_generator' => ['boolean'],
            'uses_battery' => ['boolean'],
            'uses_solar' => ['boolean'],
            'uses_wind' => ['boolean'],
            'uses_water' => ['boolean'],
            'uses_methane' => ['boolean'],
            'uses_other_power' => ['nullable', 'string', 'max:255'],
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
        ];
    }

    public function render(): View
    {
        return view('livewire.events.event-form');
    }
}
