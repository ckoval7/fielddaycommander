<?php

namespace App\Livewire\Stations;

use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Services\StationCloneService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class StationClone extends Component
{
    use AuthorizesRequests;

    private const SELECT_STATION_MESSAGE = 'Please select at least one station to clone.';

    // Modal state
    public bool $showModal = false;

    // Step 1: Event selection
    public ?int $sourceEventId = null;

    // Step 2: Station selection
    public array $selectedStationIds = [];

    public bool $selectAll = false;

    // Step 3: Clone options
    public ?int $targetEventId = null;

    public bool $copyEquipmentAssignments = true;

    public ?string $nameSuffix = null;

    // Available stations from selected event
    public Collection $availableStations;

    // Conflict preview
    public ?array $conflictPreview = null;

    public bool $showConflicts = false;

    public function mount(): void
    {
        $this->authorize('create', Station::class);
        $this->availableStations = collect();

        // Default target to context event
        $contextEvent = app(\App\Services\EventContextService::class)->getContextEvent();
        if ($contextEvent) {
            $this->targetEventId = $contextEvent->id;
        }
    }

    /**
     * Get events that have stations for cloning.
     */
    #[Computed]
    public function sourceEvents()
    {
        return Event::query()
            ->withoutTrashed()
            ->has('eventConfiguration.stations')
            ->where('end_time', '<', now()) // Only completed/past events
            ->with('eventConfiguration')
            ->orderByDesc('start_time')
            ->get()
            ->map(fn ($event) => [
                'id' => $event->id,
                'name' => $event->name.' ('.$event->start_time->format('M d, Y').')',
                'station_count' => $event->eventConfiguration?->stations()->count() ?? 0,
            ]);
    }

    /**
     * Get available events for target (excluding source event).
     */
    #[Computed]
    public function targetEvents()
    {
        return Event::query()
            ->withoutTrashed()
            ->whereHas('eventConfiguration')
            ->when($this->sourceEventId, fn ($q) => $q->where('id', '!=', $this->sourceEventId))
            ->where(function ($q) {
                // Only future or active events
                $q->where('start_time', '>=', now())
                    ->orWhere('is_active', true);
            })
            ->with('eventConfiguration')
            ->orderBy('start_time')
            ->get()
            ->map(fn ($event) => [
                'id' => $event->eventConfiguration->id,
                'name' => $event->name.' ('.$event->start_time->format('M d, Y').')',
            ]);
    }

    /**
     * Automatically load stations when source event changes.
     */
    public function updatedSourceEventId(): void
    {
        $this->loadStationsFromEvent();
    }

    /**
     * Load stations from the selected source event.
     */
    public function loadStationsFromEvent(): void
    {
        if (! $this->sourceEventId) {
            $this->availableStations = collect();
            $this->selectedStationIds = [];
            $this->selectAll = false;

            return;
        }

        $event = Event::with('eventConfiguration')->find($this->sourceEventId);
        $eventConfigId = $event?->eventConfiguration?->id;

        if (! $eventConfigId) {
            $this->availableStations = collect();
            $this->selectedStationIds = [];
            $this->selectAll = false;

            return;
        }

        $this->availableStations = Station::query()
            ->where('event_configuration_id', $eventConfigId)
            ->with([
                'primaryRadio',
                'additionalEquipment' => function ($query) {
                    $query->wherePivot('status', 'committed')
                        ->orWherePivot('status', 'delivered');
                },
            ])
            ->withCount('additionalEquipment')
            ->orderBy('name')
            ->get();

        // Select all by default
        $this->selectedStationIds = $this->availableStations->pluck('id')->toArray();
        $this->selectAll = true;
    }

    /**
     * Toggle select all stations.
     */
    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedStationIds = $this->availableStations->pluck('id')->toArray();
        } else {
            $this->selectedStationIds = [];
        }
    }

    /**
     * Update selectAll state when individual checkboxes change.
     */
    public function updatedSelectedStationIds(): void
    {
        $this->selectAll = count($this->selectedStationIds) === $this->availableStations->count();
    }

    /**
     * Check for conflicts before cloning.
     */
    public function checkForConflicts(): void
    {
        $this->authorize('create', Station::class);

        $this->resetValidation();

        $validated = $this->validate();

        if (empty($validated['selectedStationIds'])) {
            $this->addError('selectedStationIds', self::SELECT_STATION_MESSAGE);

            return;
        }

        // Validate target event is different from source
        $sourceEvent = Event::find($validated['sourceEventId']);
        $targetEventConfig = EventConfiguration::find($validated['targetEventId']);

        if ($sourceEvent->eventConfiguration->id === $targetEventConfig->id) {
            $this->addError('targetEventId', 'Target event must be different from source event.');

            return;
        }

        // Preview clone to detect conflicts
        $service = app(StationCloneService::class);
        $this->conflictPreview = $service->previewClone(
            $sourceEvent->eventConfiguration->id,
            $validated['targetEventId'],
            $validated['selectedStationIds'],
            $validated['copyEquipmentAssignments']
        );

        // If there are conflicts, show them
        if (! empty($this->conflictPreview['conflicts'])) {
            $this->showConflicts = true;
        } else {
            // No conflicts, proceed with cloning
            $this->proceedWithClone();
        }
    }

    /**
     * Continue cloning and skip equipment conflicts.
     */
    public function continueWithSkip(): void
    {
        $this->showConflicts = false;
        $this->proceedWithClone(true);
    }

    /**
     * Clone selected stations to target event.
     */
    public function proceedWithClone(bool $skipConflicts = false): void
    {
        $this->authorize('create', Station::class);

        $validated = $this->validate();

        // Get source event configuration
        $sourceEvent = Event::find($validated['sourceEventId']);

        // Use the StationCloneService
        $service = app(StationCloneService::class);
        $result = $service->cloneStations(
            $sourceEvent->eventConfiguration->id,
            $validated['targetEventId'],
            $validated['selectedStationIds'],
            [
                'copy_equipment' => $validated['copyEquipmentAssignments'],
                'name_suffix' => $validated['nameSuffix'],
                'skip_conflicts' => $skipConflicts,
            ]
        );

        // Show success message with detailed summary
        if ($result['success'] && $result['stations_cloned'] > 0) {
            $description = sprintf(
                "Stations Created: %d\nEquipment Assigned: %d%s",
                $result['stations_cloned'],
                $result['equipment_assigned'],
                $result['equipment_skipped'] > 0 ? "\nEquipment Skipped: {$result['equipment_skipped']} (conflicts)" : ''
            );

            $this->dispatch('toast', [
                'title' => 'Successfully Cloned Stations',
                'description' => $description,
                'icon' => 'o-check-circle',
                'css' => 'alert-success',
            ]);

            // Emit event to parent component
            $this->dispatch('stations-cloned');

            // Close modal
            $this->closeModal();
        }

        // Show warnings
        if (! empty($result['warnings'])) {
            foreach ($result['warnings'] as $warning) {
                $this->dispatch('toast', [
                    'title' => 'Warning',
                    'description' => $warning,
                    'icon' => 'o-exclamation-triangle',
                    'css' => 'alert-warning',
                ]);
            }
        }

        // Show errors
        if (! empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->dispatch('toast', [
                    'title' => 'Error',
                    'description' => $error,
                    'icon' => 'o-x-circle',
                    'css' => 'alert-error',
                ]);
            }
        }

        if (! $result['success']) {
            $this->addError('general', 'Unable to clone stations. Please check the errors above.');
        }
    }

    /**
     * Open the clone modal.
     */
    #[On('open-clone-modal')]
    public function openModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    /**
     * Close the clone modal.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Cancel conflict preview and return to clone options.
     */
    public function cancelConflictPreview(): void
    {
        $this->showConflicts = false;
        $this->conflictPreview = null;
    }

    /**
     * Get icon name for equipment type.
     */
    public function getEquipmentIcon(string $type): string
    {
        return Equipment::typeIcon($type);
    }

    /**
     * Reset form to initial state.
     */
    public function resetForm(): void
    {
        $this->reset([
            'sourceEventId',
            'selectedStationIds',
            'selectAll',
            'nameSuffix',
            'copyEquipmentAssignments',
            'conflictPreview',
            'showConflicts',
        ]);

        $this->availableStations = collect();
        $this->copyEquipmentAssignments = true;

        // Reset to context event (session-overridden or active event)
        $activeEvent = app(\App\Services\EventContextService::class)->getContextEvent();
        if ($activeEvent) {
            $this->targetEventId = $activeEvent->id;
        }
    }

    /**
     * Validation rules.
     */
    protected function rules(): array
    {
        return [
            'sourceEventId' => ['required', 'exists:events,id'],
            'selectedStationIds' => ['required', 'array', 'min:1'],
            'selectedStationIds.*' => ['exists:stations,id'],
            'targetEventId' => ['required', 'exists:event_configurations,id'],
            'copyEquipmentAssignments' => ['boolean'],
            'nameSuffix' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Validation messages.
     */
    protected function messages(): array
    {
        return [
            'sourceEventId.required' => 'Please select a source event.',
            'selectedStationIds.required' => self::SELECT_STATION_MESSAGE,
            'selectedStationIds.min' => self::SELECT_STATION_MESSAGE,
            'targetEventId.required' => 'Please select a target event.',
            'nameSuffix.max' => 'Name suffix must not exceed 50 characters.',
        ];
    }

    /**
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.stations.station-clone', [
            'sourceEvents' => $this->sourceEvents,
            'targetEvents' => $this->targetEvents,
        ])->layout('layouts.app');
    }
}
