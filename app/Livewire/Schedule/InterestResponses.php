<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Schedule;

use App\Enums\InterestAvailability;
use App\Models\AuditLog;
use App\Models\EventConfiguration;
use App\Models\EventInterest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Interest tab on Manage Schedule: turns the public interest form on or
 * off for an event and lists who has responded.
 */
class InterestResponses extends Component
{
    #[Locked]
    public int $eventConfigId;

    public bool $formEnabled = false;

    public function mount(int $eventConfigId): void
    {
        Gate::authorize('manage-shifts');

        $this->eventConfigId = $eventConfigId;
        $this->formEnabled = (bool) $this->eventConfig->interest_form_enabled;
    }

    #[Computed]
    public function eventConfig(): EventConfiguration
    {
        return EventConfiguration::with('event')->findOrFail($this->eventConfigId);
    }

    /**
     * Whether the event has ended, so the form can no longer open.
     */
    #[Computed]
    public function eventIsOver(): bool
    {
        return $this->eventConfig->event?->end_time?->isPast() ?? true;
    }

    /**
     * @return Collection<int, EventInterest>
     */
    #[Computed]
    public function responses(): Collection
    {
        return $this->eventConfig->interests()->latest()->get();
    }

    /**
     * How many responses picked each block of time.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function availabilityCounts(): array
    {
        $counts = [];

        foreach (InterestAvailability::cases() as $block) {
            $counts[$block->value] = $this->responses
                ->filter(fn (EventInterest $interest): bool => $interest->availability->contains($block))
                ->count();
        }

        return $counts;
    }

    /**
     * Every respondent's address, comma-separated, for a Bcc line.
     */
    #[Computed]
    public function emailList(): string
    {
        return $this->responses->pluck('email')->implode(', ');
    }

    public function updatedFormEnabled(bool $value): void
    {
        Gate::authorize('manage-shifts');

        if ($value && $this->eventIsOver) {
            $this->formEnabled = false;

            return;
        }

        $config = $this->eventConfig;
        $config->update(['interest_form_enabled' => $value]);

        AuditLog::log(
            action: $value ? 'interest.form.opened' : 'interest.form.closed',
            auditable: $config,
        );
    }

    public function deleteResponse(int $interestId): void
    {
        Gate::authorize('manage-shifts');

        $interest = $this->eventConfig->interests()->findOrFail($interestId);

        AuditLog::log(
            action: 'interest.response.deleted',
            auditable: $interest,
            oldValues: ['name' => $interest->name, 'callsign' => $interest->callsign],
        );

        $interest->delete();

        unset($this->responses, $this->availabilityCounts, $this->emailList);
    }

    public function render(): View
    {
        return view('livewire.schedule.interest-responses');
    }
}
