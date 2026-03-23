<?php

namespace App\Livewire\Reports;

use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ReportsIndex extends Component
{
    public ?Event $event = null;

    public function mount(): void
    {
        $this->event = Event::active()
            ->with(['eventConfiguration.section', 'eventConfiguration.operatingClass'])
            ->first();
    }

    protected function config(): ?EventConfiguration
    {
        return $this->event?->eventConfiguration;
    }

    /**
     * @return array<int, array{hour: string, total: int, cw: int, phone: int, digital: int}>
     */
    #[Computed]
    public function qsoRateByHour(): array
    {
        if (! $this->config()) {
            return [];
        }

        $contacts = $this->config()->contacts()
            ->notDuplicate()
            ->with('mode')
            ->orderBy('qso_time')
            ->get();

        if ($contacts->isEmpty()) {
            return [];
        }

        $firstHour = $contacts->first()->qso_time->copy()->startOfHour();
        $lastHour = $contacts->last()->qso_time->copy()->startOfHour();

        $grouped = $contacts->groupBy(fn ($c) => $c->qso_time->format('Y-m-d H:00'));

        $rows = [];
        $cursor = $firstHour->copy();

        while ($cursor <= $lastHour) {
            $key = $cursor->format('Y-m-d H:00');
            $hour = $grouped->get($key, collect());

            $rows[] = [
                'hour' => $cursor->format('Y-m-d H:i').' UTC',
                'total' => $hour->count(),
                'cw' => $hour->filter(fn ($c) => $c->mode?->category === 'CW')->count(),
                'phone' => $hour->filter(fn ($c) => $c->mode?->category === 'Phone')->count(),
                'digital' => $hour->filter(fn ($c) => $c->mode?->category === 'Digital')->count(),
            ];

            $cursor->addHour();
        }

        return $rows;
    }

    /**
     * @return array<int, array{call_sign: string, name: string, total_logged: int, valid_qsos: int, duplicates: int}>
     */
    #[Computed]
    public function operatorSummary(): array
    {
        if (! $this->config()) {
            return [];
        }

        return Contact::where('event_configuration_id', $this->config()->id)
            ->whereNotNull('logger_user_id')
            ->selectRaw('logger_user_id,
                count(*) as total_logged,
                sum(case when is_duplicate = 0 then 1 else 0 end) as valid_qsos,
                sum(case when is_duplicate = 1 then 1 else 0 end) as duplicates')
            ->groupBy('logger_user_id')
            ->with('logger')
            ->get()
            ->map(fn ($row) => [
                'call_sign' => $row->logger?->call_sign ?? '—',
                'name' => trim(($row->logger?->first_name ?? '').' '.($row->logger?->last_name ?? '')),
                'total_logged' => (int) $row->total_logged,
                'valid_qsos' => (int) $row->valid_qsos,
                'duplicates' => (int) $row->duplicates,
            ])
            ->sortByDesc('valid_qsos')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{code: string, name: string, count: int}>
     */
    #[Computed]
    public function sectionCounts(): array
    {
        if (! $this->config()) {
            return [];
        }

        return Contact::where('event_configuration_id', $this->config()->id)
            ->notDuplicate()
            ->whereNotNull('section_id')
            ->selectRaw('section_id, count(*) as contact_count')
            ->groupBy('section_id')
            ->with('section')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->section?->code ?? '?',
                'name' => $row->section?->name ?? 'Unknown',
                'count' => (int) $row->contact_count,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.reports.index')->layout('layouts.app');
    }
}
