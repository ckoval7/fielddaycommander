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
        return $this->eventConfig->event?->end_time?->lt(appNow()) ?? true;
    }

    /**
     * @return Collection<int, EventInterest>
     */
    #[Computed]
    public function responses(): Collection
    {
        return $this->eventConfig->interests()->orderBy('last_name')->orderBy('first_name')->get();
    }

    /**
     * How many responses picked each block of time, in the form's order.
     *
     * @return list<array{label: string, count: int}>
     */
    #[Computed]
    public function availabilityTally(): array
    {
        return array_map(fn (InterestAvailability $block): array => [
            'label' => $block->label(),
            'count' => $this->responses
                ->filter(fn (EventInterest $interest): bool => $interest->availability->contains($block))
                ->count(),
        ], InterestAvailability::cases());
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

        $this->dispatch('toast', title: 'Success', description: $value ? 'Interest form opened' : 'Interest form closed', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function deleteResponse(int $interestId): void
    {
        Gate::authorize('manage-shifts');

        $interest = $this->eventConfig->interests()->findOrFail($interestId);

        AuditLog::log(
            action: 'interest.response.deleted',
            auditable: $interest,
            oldValues: ['name' => $interest->full_name, 'callsign' => $interest->callsign],
        );

        $interest->delete();

        unset($this->responses, $this->availabilityTally, $this->emailList);

        $this->dispatch('toast', title: 'Success', description: 'Response deleted', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function render(): View
    {
        return view('livewire.schedule.interest-responses');
    }
}
