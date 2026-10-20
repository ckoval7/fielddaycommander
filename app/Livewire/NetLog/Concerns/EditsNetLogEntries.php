<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\NetLog\Concerns;

use App\Enums\NetPriority;
use App\Events\NetLogChanged;
use App\Models\NetLogEntry;

/**
 * Saving, deleting and undoing recalled net log entries. Operators change
 * their own entries; managers change anyone's.
 */
trait EditsNetLogEntries
{
    /**
     * Save a recalled entry from its edited line.
     */
    public function updateEntry(int $entryId, string $line): void
    {
        $entry = $this->findEventEntry($entryId);

        if (! auth()->user()?->can('update', $entry)) {
            $this->dispatch('toast', title: 'You can only edit your own entries', type: 'error');

            return;
        }

        $parsed = $this->parseLine($line);

        if ($parsed === null) {
            return;
        }

        $entry->update([
            'from_station' => $parsed['from'],
            'to_station' => $parsed['to'],
            // Keep the operator snapshot unless the station itself changed.
            'from_callsign' => $parsed['from'] === $entry->from_station ? $entry->from_callsign : $this->resolveCallsign($parsed['from']),
            'to_callsign' => $parsed['to'] === $entry->to_station ? $entry->to_callsign : $this->resolveCallsign($parsed['to']),
            'message' => $parsed['message'],
            // A recalled line carries its priority word, so none means routine.
            'priority' => $parsed['priority'] ?? NetPriority::Routine,
        ]);

        NetLogChanged::dispatch($this->event->id, $entry->id);
        $this->reset('exchangeInput', 'suggestions');
        unset($this->entries);

        $this->dispatch('toast', title: 'Entry updated', type: 'success');
    }

    public function deleteEntry(int $entryId): void
    {
        $entry = $this->findEventEntry($entryId);

        if (! auth()->user()?->can('delete', $entry)) {
            $this->dispatch('toast', title: 'You can only delete your own entries', type: 'error');

            return;
        }

        $entry->delete();
        NetLogChanged::dispatch($this->event->id, $entry->id);
        $this->reset('exchangeInput', 'suggestions');
        unset($this->entries);
    }

    public function restoreEntry(int $entryId): void
    {
        $entry = NetLogEntry::onlyTrashed()->forEvent($this->event->eventConfiguration->id)->findOrFail($entryId);

        if (! auth()->user()?->can('delete', $entry)) {
            return;
        }

        $entry->restore();
        NetLogChanged::dispatch($this->event->id, $entry->id);
        unset($this->entries);
    }

    /**
     * Find an entry that belongs to this component's event, or 404.
     */
    protected function findEventEntry(int $entryId): NetLogEntry
    {
        return NetLogEntry::forEvent($this->event->eventConfiguration->id)->findOrFail($entryId);
    }
}
