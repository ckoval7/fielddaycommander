<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Guestbook;

use App\Models\GuestbookEntry;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class GuestbookList extends Component
{
    public int $limit = 30;

    public Collection $entries;

    public function mount(): void
    {
        $this->loadEntries();
    }

    #[On('guestbook-entry-created')]
    public function refreshEntries(): void
    {
        $this->loadEntries();
    }

    public function loadEntries(): void
    {
        $activeEvent = app(EventContextService::class)->getContextEvent();

        if (! $activeEvent || ! $activeEvent->eventConfiguration) {
            $this->entries = collect();

            return;
        }

        $this->entries = GuestbookEntry::where('event_configuration_id', $activeEvent->eventConfiguration->id)
            ->with(['verifiedBy'])
            ->latest()
            ->limit($this->limit)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.guestbook.guestbook-list')->layout('layouts.app');
    }
}
