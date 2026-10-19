<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\NetLog;

use App\Enums\NetPriority;
use App\Events\NetLogChanged;
use App\Models\Event;
use App\Models\NetLogEntry;
use App\Services\TacticalCallsignResolver;
use App\Support\BroadcastChannels;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Net control's log for a directed net: time, from, to, message and priority.
 *
 * Every net control screen at the event sees new entries live over Reverb.
 */
class NetLog extends Component
{
    use AuthorizesRequests, WithPagination;

    private const DATETIME_LOCAL_FORMAT = 'Y-m-d\TH:i';

    private const PER_PAGE = 50;

    public Event $event;

    public string $fromStation = '';

    public string $toStation = '';

    public string $message = '';

    public string $priority = 'routine';

    public bool $showEditModal = false;

    public ?int $editingEntryId = null;

    public ?string $editLoggedAt = null;

    public string $editFromStation = '';

    public string $editToStation = '';

    public string $editMessage = '';

    public string $editPriority = 'routine';

    public function mount(Event $event): void
    {
        abort_if($event->eventConfiguration === null, 404);

        $this->event = $event;
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            BroadcastChannels::privateListener(
                BroadcastChannels::event($this->event->id),
                '.'.BroadcastChannels::NET_LOG_CHANGED,
            ) => 'refreshEntries',
        ];
    }

    /**
     * @return LengthAwarePaginator<int, NetLogEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return NetLogEntry::forEvent($this->event->eventConfiguration->id)
            ->with('user')
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Tactical names offered as suggestions for From and To.
     *
     * @return list<string>
     */
    #[Computed]
    public function tacticalNames(): array
    {
        return app(TacticalCallsignResolver::class)->tacticalNames($this->event->eventConfiguration)->all();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    #[Computed]
    public function priorityOptions(): array
    {
        return collect(NetPriority::cases())
            ->map(fn (NetPriority $priority) => ['id' => $priority->value, 'name' => $priority->label()])
            ->all();
    }

    public function refreshEntries(): void
    {
        unset($this->entries);
    }

    public function logEntry(): void
    {
        $this->authorize('create', NetLogEntry::class);

        $validated = $this->validate([
            'fromStation' => ['required', 'string', 'max:50'],
            'toStation' => ['nullable', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:2000'],
            'priority' => ['required', Rule::enum(NetPriority::class)],
        ]);

        $fromStation = trim($validated['fromStation']);
        $toStation = trim((string) $validated['toStation']) ?: null;

        $entry = NetLogEntry::create([
            'event_configuration_id' => $this->event->eventConfiguration->id,
            'user_id' => auth()->id(),
            'logged_at' => appNow(),
            'from_station' => $fromStation,
            'to_station' => $toStation,
            'from_callsign' => $this->resolveCallsign($fromStation),
            'to_callsign' => $this->resolveCallsign($toStation),
            'message' => $validated['message'],
            'priority' => $validated['priority'],
        ]);

        $this->broadcastChange($entry);

        $this->reset('fromStation', 'toStation', 'message', 'priority');
        $this->resetPage();
        unset($this->entries);

        $this->dispatch('net-log-entry-saved');
        $this->dispatch('toast', title: 'Entry logged', type: 'success');
    }

    public function editEntry(int $entryId): void
    {
        $entry = $this->findEventEntry($entryId);
        $this->authorize('update', $entry);

        $this->editingEntryId = $entry->id;
        $this->editLoggedAt = $entry->logged_at->format(self::DATETIME_LOCAL_FORMAT);
        $this->editFromStation = $entry->from_station;
        $this->editToStation = (string) $entry->to_station;
        $this->editMessage = $entry->message;
        $this->editPriority = $entry->priority->value;
        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function updateEntry(): void
    {
        $entry = $this->findEventEntry((int) $this->editingEntryId);
        $this->authorize('update', $entry);

        $validated = $this->validate([
            'editLoggedAt' => ['required', 'date'],
            'editFromStation' => ['required', 'string', 'max:50'],
            'editToStation' => ['nullable', 'string', 'max:50'],
            'editMessage' => ['required', 'string', 'max:2000'],
            'editPriority' => ['required', Rule::enum(NetPriority::class)],
        ]);

        $fromStation = trim($validated['editFromStation']);
        $toStation = trim((string) $validated['editToStation']) ?: null;

        $entry->update([
            'logged_at' => Carbon::parse($validated['editLoggedAt']),
            'from_station' => $fromStation,
            'to_station' => $toStation,
            // Keep the operator snapshot unless the station itself changed.
            'from_callsign' => $fromStation === $entry->from_station ? $entry->from_callsign : $this->resolveCallsign($fromStation),
            'to_callsign' => $toStation === $entry->to_station ? $entry->to_callsign : $this->resolveCallsign($toStation),
            'message' => $validated['editMessage'],
            'priority' => $validated['editPriority'],
        ]);

        $this->broadcastChange($entry);
        $this->closeEditModal();
        unset($this->entries);

        $this->dispatch('toast', title: 'Entry updated', type: 'success');
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingEntryId = null;
        $this->reset('editLoggedAt', 'editFromStation', 'editToStation', 'editMessage', 'editPriority');
        $this->resetValidation();
    }

    public function deleteEntry(int $entryId): void
    {
        $entry = $this->findEventEntry($entryId);
        $this->authorize('delete', $entry);

        $entry->delete();
        $this->broadcastChange($entry);
        unset($this->entries);

        $this->dispatch('toast', title: 'Entry deleted', type: 'success');
    }

    /**
     * Find an entry that belongs to this component's event, or 404.
     */
    protected function findEventEntry(int $entryId): NetLogEntry
    {
        return NetLogEntry::forEvent($this->event->eventConfiguration->id)->findOrFail($entryId);
    }

    /**
     * Callsign of the operator on duty under a tactical name, snapshotted on
     * the entry so the log still shows who it was after the shift changes.
     */
    protected function resolveCallsign(?string $station): ?string
    {
        return app(TacticalCallsignResolver::class)->resolve($this->event->eventConfiguration, $station);
    }

    protected function broadcastChange(NetLogEntry $entry): void
    {
        NetLogChanged::dispatch($this->event->id, $entry->id);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'fromStation' => 'from',
            'toStation' => 'to',
            'editLoggedAt' => 'time',
            'editFromStation' => 'from',
            'editToStation' => 'to',
            'editMessage' => 'message',
            'editPriority' => 'priority',
        ];
    }

    public function render(): View
    {
        return view('livewire.net-log.net-log')->layout('layouts.app');
    }
}
