<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Models\Contact;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

class RecentContacts extends AbstractContactWidget
{
    #[Computed]
    public function recentContacts(): Collection
    {
        if (! $this->event?->eventConfiguration) {
            return collect();
        }

        return Contact::where('event_configuration_id', $this->event->eventConfiguration->id)
            ->with(['band', 'mode', 'section', 'logger'])
            ->orderBy('qso_time', 'desc')
            ->limit(10)
            ->get();
    }

    protected function computedPropertiesToClear(): array
    {
        return ['recentContacts'];
    }

    protected function getWidgetName(): string
    {
        return 'Recent Contacts';
    }

    protected function getViewName(): string
    {
        return 'livewire.dashboard.widgets.recent-contacts';
    }
}
