<?php

namespace App\Livewire\Events;

use App\Models\Event;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

class EventsList extends Component
{
    use AuthorizesRequests, WithPagination;

    public bool $showArchived = false;

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    public function mount(): void
    {
        $this->authorize('view-events');
    }

    public function updatedShowArchived(): void
    {
        $this->resetPage();
    }

    public function getEventsProperty()
    {
        return Event::query()
            ->when(! $this->showArchived, fn (Builder $query) => $query->withoutTrashed())
            ->when($this->showArchived, fn (Builder $query) => $query->withTrashed())
            ->with([
                'eventType',
                'eventConfiguration' => function ($query) {
                    $query->withCount('contacts');
                },
                'eventConfiguration.section',
                'eventConfiguration.operatingClass',
            ])
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate(25);
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function delete(int $eventId): void
    {
        $this->authorize('delete-events');

        $event = Event::findOrFail($eventId);

        // Check if event has contacts
        $hasContacts = $event->eventConfiguration?->hasContacts() ?? false;

        if ($hasContacts) {
            // Soft delete if has contacts
            $event->delete();
            $this->dispatch('notify', title: 'Event Archived', description: "Event '{$event->name}' has been archived (soft deleted) because it has contacts.");
        } else {
            // Hard delete if no contacts
            $event->forceDelete();
            $this->dispatch('notify', title: 'Event Deleted', description: "Event '{$event->name}' has been permanently deleted.");
        }
    }

    public function render(): View
    {
        return view('livewire.events.events-list', [
            'events' => $this->events,
        ])->layout('layouts.app');
    }
}
