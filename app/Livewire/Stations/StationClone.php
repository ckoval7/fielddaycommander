<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Stations;

use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Services\EventContextService;
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
        $contextEvent = app(EventContextService::class)->getContextEvent();
        if ($contextEvent) {
            $this->targetEventId = $contextEvent->eventConfiguration?->id;
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

        $clonedWithWarnings = false;

        if ($result['success'] && $result['stations_cloned'] > 0) {
            $clonedWithWarnings = $this->announceClonedStations($result);
        }

        // Warnings already shown in the success toast aren't repeated
        if (! $clonedWithWarnings) {
            $this->toastEach($result['warnings'] ?? [], 'Warning', 'phosphor-warning', 'alert-warning');
        }

        $this->toastEach($result['errors'] ?? [], 'Error', 'phosphor-x-circle', 'alert-error');

        if (! $result['success']) {
            $this->addError('general', 'Unable to clone stations. Please review the errors and try again.');
        }
    }

    /**
     * Show the success toast with a summary of what was cloned, then close the
     * modal. Toasts show one at a time, so warnings go in the same toast
     * rather than replacing it. Returns whether the toast carried warnings.
     *
     * @param  array<string, mixed>  $result
     */
    private function announceClonedStations(array $result): bool
    {
        $description = sprintf(
            "Stations Created: %d\nEquipment Assigned: %d%s",
            $result['stations_cloned'],
            $result['equipment_assigned'],
            $result['equipment_skipped'] > 0 ? "\nEquipment Skipped: {$result['equipment_skipped']} (conflicts)" : ''
        );

        $withWarnings = ! empty($result['warnings']);

        $this->dispatch('toast', [
            'title' => 'Stations Cloned',
            'description' => $withWarnings
                ? $description."\n".implode("\n", $result['warnings'])
                : $description,
            'icon' => $withWarnings ? 'phosphor-warning' : 'phosphor-check-circle',
            'css' => $withWarnings ? 'alert-warning' : 'alert-success',
            'timeout' => $withWarnings ? 8000 : 3000,
        ]);

        // Emit event to parent component
        $this->dispatch('stations-cloned');

        $this->closeModal();

        return $withWarnings;
    }

    /**
     * Show one toast per message.
     *
     * @param  array<int, string>  $messages
     */
    private function toastEach(array $messages, string $title, string $icon, string $css): void
    {
        foreach ($messages as $message) {
            $this->dispatch('toast', [
                'title' => $title,
                'description' => $message,
                'icon' => $icon,
                'css' => $css,
            ]);
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
        $activeEvent = app(EventContextService::class)->getContextEvent();
        if ($activeEvent) {
            $this->targetEventId = $activeEvent->eventConfiguration?->id;
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
        ])->layout('layouts.app', ['title' => 'Clone Stations']);
    }

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'sourceEventId' => 'source event',
            'selectedStationIds.*' => 'station',
            'targetEventId' => 'target event',
        ];
    }
}
