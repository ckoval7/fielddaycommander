<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Checklists;

use App\Enums\ChecklistScope;
use App\Models\Checklist;
use App\Models\EventConfiguration;
use App\Services\ChecklistService;
use App\Services\EventContextService;
use App\Support\BroadcastChannels;
use App\Support\ChecklistTemplates;
use App\Support\StationMode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The event's checklists, each with its live progress.
 */
class ChecklistIndex extends Component
{
    public ?EventConfiguration $eventConfig = null;

    public function mount(): void
    {
        $this->eventConfig = app(EventContextService::class)->getContextEvent()?->eventConfiguration;

        if ($this->eventConfig) {
            ChecklistTemplates::seedDefaults($this->eventConfig);
        }
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if (! $this->eventConfig) {
            return [];
        }

        return [
            BroadcastChannels::privateListener(
                BroadcastChannels::event($this->eventConfig->event_id),
                '.'.BroadcastChannels::CHECKLIST_CHANGED,
            ) => 'refreshProgress',
        ];
    }

    public function refreshProgress(): void
    {
        unset($this->checklists);
    }

    /**
     * Checklists with their progress. A station-mode screen only shows the
     * per-station checklists it can work on.
     *
     * @return Collection<int, array{checklist: Checklist, progress: array{done: int, total: int}}>
     */
    #[Computed]
    public function checklists(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        $service = app(ChecklistService::class);

        return $this->eventConfig->checklists()
            ->ordered()
            ->withCount('items')
            ->when(StationMode::isActive(), fn ($q) => $q->where('scope', ChecklistScope::Station))
            ->get()
            ->map(fn (Checklist $checklist) => [
                'checklist' => $checklist,
                'progress' => $service->progress($checklist),
            ]);
    }

    /**
     * Whether the event has operational periods. Without them a per-period
     * checklist runs once, like an event-wide one.
     */
    #[Computed]
    public function hasPeriods(): bool
    {
        return $this->eventConfig?->operationalPeriods()->exists() ?? false;
    }

    public function render(): View
    {
        return view('livewire.checklists.checklist-index')
            ->layout('layouts.app');
    }
}
