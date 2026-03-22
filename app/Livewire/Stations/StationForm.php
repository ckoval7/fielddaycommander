<?php

namespace App\Livewire\Stations;

use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class StationForm extends Component
{
    use AuthorizesRequests;

    // Modal state
    public bool $showModal = false;

    // Station being edited (null for create mode)
    public ?int $stationId = null;

    // Active tab for edit mode
    public string $activeTab = 'configuration';

    // Form fields
    public string $name = '';

    public ?int $event_configuration_id = null;

    public ?int $radio_equipment_id = null;

    public bool $is_gota = false;

    public bool $is_vhf_only = false;

    public bool $is_satellite = false;

    public ?int $max_power_watts = null;

    public ?string $power_source_description = null;

    // Available radios for searchable select
    public Collection $availableRadios;

    public function mount(?Station $station = null): void
    {
        // Convert Station model to ID if provided (from route parameter)
        $this->stationId = $station?->id;

        if ($this->stationId) {
            $this->loadStation();
            $this->authorize('update', $station);

            // Open specific tab if requested via query parameter
            $requestedTab = request()->query('tab');
            if ($requestedTab && in_array($requestedTab, ['configuration', 'equipment', 'activity'])) {
                $this->activeTab = $requestedTab;
            }
        } else {
            $this->authorize('create', Station::class);
            // Default to context event (session-overridden or active event)
            $activeEvent = app(\App\Services\EventContextService::class)->getContextEvent();
            if ($activeEvent) {
                $this->event_configuration_id = $activeEvent->eventConfiguration?->id;
            }
        }

        // Initialize available radios
        $this->searchRadios();
    }

    private function loadStation(): void
    {
        $station = Station::with('eventConfiguration', 'primaryRadio')->findOrFail($this->stationId);

        $this->name = $station->name;
        $this->event_configuration_id = $station->event_configuration_id;
        $this->radio_equipment_id = $station->radio_equipment_id;
        $this->is_gota = $station->is_gota;
        $this->is_vhf_only = $station->is_vhf_only;
        $this->is_satellite = $station->is_satellite;
        $this->max_power_watts = $station->max_power_watts;
        $this->power_source_description = $station->power_source_description;
    }

    #[Computed]
    public function events()
    {
        return Event::with('eventConfiguration')
            ->whereHas('eventConfiguration')
            ->where('is_active', true)
            ->orderBy('start_time', 'desc')
            ->get()
            ->map(fn ($event) => [
                'id' => $event->eventConfiguration->id,
                'name' => $event->name.' ('.$event->start_time->format('M d, Y').')',
            ]);
    }

    #[Computed]
    public function selectedEvent(): ?EventConfiguration
    {
        if (! $this->event_configuration_id) {
            return null;
        }

        return EventConfiguration::with('operatingClass')->find($this->event_configuration_id);
    }

    #[Computed]
    public function maxPowerLimit(): ?int
    {
        return $this->selectedEvent?->operatingClass?->max_power_watts;
    }

    #[Computed]
    public function allowsGota(): bool
    {
        return $this->selectedEvent?->operatingClass?->allows_gota ?? false;
    }

    /**
     * Search for available radio equipment.
     * Called on mount and when user types in the searchable select.
     */
    public function searchRadios(string $value = ''): void
    {
        $query = Equipment::query()
            ->where('type', 'radio')
            ->where(function ($q) use ($value) {
                $q->where('make', 'like', "%{$value}%")
                    ->orWhere('model', 'like', "%{$value}%");
            })
            ->orderBy('make')
            ->orderBy('model')
            ->take(50);

        // Include selected radio if editing
        $selectedRadio = $this->radio_equipment_id
            ? Equipment::where('id', $this->radio_equipment_id)->get()
            : collect();

        $this->availableRadios = $query->get()
            ->merge($selectedRadio)
            ->unique('id')
            ->map(function ($equipment) {
                $label = "{$equipment->make} {$equipment->model}";

                // Show power output if available
                if ($equipment->power_output_watts) {
                    $label .= " ({$equipment->power_output_watts}W)";
                }

                // Show owner info
                $label .= ' - '.$equipment->owner_name;

                // Show current commitment status if assigned to event
                if ($this->event_configuration_id && $equipment->current_commitment) {
                    $commitment = $equipment->current_commitment;
                    if ($commitment->event_id !== $this->selectedEvent?->event_id) {
                        $label .= ' [Committed to '.$commitment->event->name.']';
                    }
                }

                return [
                    'id' => $equipment->id,
                    'name' => $label,
                    'power_output_watts' => $equipment->power_output_watts,
                ];
            });
    }

    public function updated($property): void
    {
        // Clear GOTA flag if event doesn't allow GOTA
        if ($property === 'event_configuration_id' && ! $this->allowsGota) {
            $this->is_gota = false;
        }

        // Auto-populate max power from selected radio
        if ($property === 'radio_equipment_id' && $this->radio_equipment_id) {
            $radio = Equipment::find($this->radio_equipment_id);
            if ($radio && $radio->power_output_watts && ! $this->max_power_watts) {
                $this->max_power_watts = $radio->power_output_watts;
            }
        }
    }

    public function save(): void
    {
        try {
            $validated = $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->activeTab = 'configuration';

            throw $e;
        }

        // Check for only one GOTA station per event
        if ($validated['is_gota']) {
            $existingGota = Station::where('event_configuration_id', $validated['event_configuration_id'])
                ->where('is_gota', true);

            // Exclude current station if editing
            if ($this->stationId) {
                $existingGota->where('id', '!=', $this->stationId);
            }

            if ($existingGota->exists()) {
                $this->addError('is_gota', 'This event already has a GOTA station. Only one GOTA station is allowed per event.');

                return;
            }
        }

        $stationData = [
            'name' => $validated['name'],
            'event_configuration_id' => $validated['event_configuration_id'],
            'radio_equipment_id' => $validated['radio_equipment_id'],
            'is_gota' => $validated['is_gota'],
            'is_vhf_only' => $validated['is_vhf_only'],
            'is_satellite' => $validated['is_satellite'],
            'max_power_watts' => $validated['max_power_watts']
                ?? Equipment::find($validated['radio_equipment_id'])?->power_output_watts
                ?? 100,
            'power_source_description' => $validated['power_source_description'],
        ];

        if ($this->stationId) {
            // Update existing station
            $station = Station::findOrFail($this->stationId);
            $station->update($stationData);
            $successMessage = 'Station updated successfully';
        } else {
            // Create new station
            $station = Station::create($stationData);
            $successMessage = 'Station created successfully';
        }

        // Success notification
        $this->dispatch('toast', [
            'title' => 'Success',
            'description' => $successMessage,
            'icon' => 'o-check-circle',
            'css' => 'alert-success',
        ]);

        // Emit event to refresh parent list
        $this->dispatch('station-saved');

        // Close modal or redirect
        if ($this->showModal) {
            $this->closeModal();
        } elseif (! $this->stationId && isset($station)) {
            // After creation, redirect to edit page on Equipment tab
            $this->redirect(route('stations.edit', $station).'?tab=equipment', navigate: true);
        } else {
            $this->redirect(route('stations.index'), navigate: true);
        }
    }

    public function openModal(?int $stationId = null): void
    {
        $this->resetForm();
        $this->stationId = $stationId;

        if ($stationId) {
            $this->loadStation();
        }

        $this->searchRadios();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->reset([
            'stationId',
            'name',
            'event_configuration_id',
            'radio_equipment_id',
            'is_gota',
            'is_vhf_only',
            'is_satellite',
            'max_power_watts',
            'power_source_description',
        ]);

        // Reset to context event (session-overridden or active event)
        $activeEvent = app(\App\Services\EventContextService::class)->getContextEvent();
        if ($activeEvent) {
            $this->event_configuration_id = $activeEvent->eventConfiguration?->id;
        }
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stations', 'name')
                    ->where('event_configuration_id', $this->event_configuration_id)
                    ->ignore($this->stationId),
            ],
            'event_configuration_id' => ['required', 'exists:event_configurations,id'],
            'radio_equipment_id' => ['required', 'exists:equipment,id'],
            'is_gota' => [
                'boolean',
                function ($attribute, $value, $fail) {
                    if ($value && ! $this->allowsGota) {
                        $fail('The selected event\'s operating class does not allow a GOTA station.');
                    }
                },
            ],
            'is_vhf_only' => ['boolean'],
            'is_satellite' => ['boolean'],
            'max_power_watts' => [
                'nullable',
                'integer',
                'min:1',
                'max:5000',
                function ($attribute, $value, $fail) {
                    $maxLimit = $this->maxPowerLimit;
                    if ($maxLimit && $value > $maxLimit) {
                        $fail("Warning: The power ({$value}W) exceeds the event's operating class limit of {$maxLimit}W.");
                    }
                },
            ],
            'power_source_description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Please provide a station name.',
            'name.unique' => 'A station with this name already exists for this event.',
            'event_configuration_id.required' => 'Please select an event.',
            'radio_equipment_id.required' => 'Please select a primary radio.',
            'max_power_watts.min' => 'Power output must be at least 1 watt.',
            'max_power_watts.max' => 'Power output cannot exceed 5000 watts.',
        ];
    }

    public function render(): View
    {
        return view('livewire.stations.station-form');
    }
}
