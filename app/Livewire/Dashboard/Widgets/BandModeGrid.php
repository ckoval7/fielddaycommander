<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Models\Band;
use App\Models\Contact;
use App\Models\Mode;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

class BandModeGrid extends AbstractContactWidget
{
    #[Computed]
    public function bands(): Collection
    {
        return Band::allowedForFieldDay()->ordered()->get();
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

    protected function computedPropertiesToClear(): array
    {
        return ['gridData'];
    }

    protected function getWidgetName(): string
    {
        return 'Band/Mode Activity Grid';
    }

    protected function getViewName(): string
    {
        return 'livewire.dashboard.widgets.band-mode-grid';
    }
}
