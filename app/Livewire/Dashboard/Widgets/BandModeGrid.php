<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Concerns\HasErrorBoundary;
use App\Livewire\Dashboard\Widgets\Concerns\ListensForBroadcasts;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Mode;
use App\Support\BroadcastChannels;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Band/mode contact-count grid for the active event (public landing page).
 *
 * Polls for updates; signed-in viewers also refresh immediately on
 * ContactLogged broadcasts when real-time broadcasting is available.
 */
class BandModeGrid extends Component
{
    use HasErrorBoundary;
    use ListensForBroadcasts;

    public ?Event $event = null;

    public function mount(): void
    {
        $this->event = Event::active()->with('eventConfiguration')->first();
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        // Guests on the public landing page can't join the private channel;
        // the widget polls instead.
        if (! $this->event || ! $this->canListenForBroadcasts()) {
            return [];
        }

        return BroadcastChannels::contactChangeListeners($this->event->id, 'handleContactLogged');
    }

    /**
     * Handle real-time ContactLogged broadcast.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleContactLogged(array $payload): void
    {
        try {
            unset($this->gridData);
        } catch (\Throwable $e) {
            $this->handleWidgetError($e);
        }
    }

    #[Computed]
    public function bands(): Collection
    {
        $query = Band::allowedForFieldDay()->ordered();

        if ($this->event?->eventConfiguration) {
            $query->withUncommonBandsOnlyWhenWorked($this->event->eventConfiguration->id);
        }

        return $query->get();
    }

    #[Computed]
    public function modes(): Collection
    {
        return Mode::orderBy('name')->get();
    }

    #[Computed]
    public function gridData(): array
    {
        if (! $this->event?->eventConfiguration) {
            return [];
        }

        // Single aggregation query instead of one per band/mode combination
        $counts = Contact::where('event_configuration_id', $this->event->eventConfiguration->id)
            ->notDuplicate()
            ->selectRaw('band_id, mode_id, count(*) as contact_count')
            ->groupBy('band_id', 'mode_id')
            ->get()
            ->groupBy('mode_id')
            ->map(fn ($group) => $group->pluck('contact_count', 'band_id'));

        $data = [];

        foreach ($this->modes as $mode) {
            $row = ['mode' => $mode->name];
            $modeCounts = $counts->get($mode->id, collect());

            foreach ($this->bands as $band) {
                $row[$band->id] = $modeCounts->get($band->id, 0);
            }

            $data[] = $row;
        }

        return $data;
    }

    protected function getWidgetName(): string
    {
        return 'Band/Mode Activity Grid';
    }

    public function render()
    {
        if ($this->hasError) {
            return view('livewire.dashboard.widgets.error-fallback', [
                'widgetName' => $this->getWidgetName(),
                'errorMessage' => $this->errorMessage,
            ]);
        }

        return view('livewire.dashboard.widgets.band-mode-grid');
    }
}
