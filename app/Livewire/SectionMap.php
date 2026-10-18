<?php

namespace App\Livewire;

use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Mode;
use App\Models\Section;
use App\Services\EventContextService;
use App\Support\BroadcastChannels;
use App\Support\EventSections;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

class SectionMap extends Component
{
    public ?Event $event = null;

    public array $sectionData = [];

    public int $maxCount = 0;

    public int $totalQsos = 0;

    public int $sectionsWorked = 0;

    public int $totalSections = 0;

    public bool $hasEvent = false;

    /**
     * Whether Yukon (YT) is mapped as its own section. Winter Field Day
     * recognises YT; ARRL events still fold it into Territories (TER).
     */
    public bool $splitsYukon = false;

    public function mount(): void
    {
        $service = app(EventContextService::class);
        $this->event = $service->getContextEvent();
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if (! $this->event) {
            return [];
        }

        return BroadcastChannels::contactChangeListeners($this->event->id, 'handleContactLogged');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleContactLogged(array $payload): void
    {
        // Re-render is automatic when this method is called
    }

    public function render(): View
    {
        $this->computeSectionData();

        return view('livewire.section-map');
    }

    private function computeSectionData(): void
    {
        $eventConfigId = $this->event?->eventConfiguration?->id;
        $this->hasEvent = $eventConfigId !== null;
        $this->splitsYukon = EventSections::recognisesWfdSections($this->event);

        $allSections = Section::where('is_active', true)
            ->whereNotIn('code', EventSections::foldedCodes($this->event))
            ->orderBy('code')
            ->get()
            ->keyBy('code');

        $this->totalSections = $allSections->count();
        $this->sectionData = [];
        $this->totalQsos = 0;
        $this->sectionsWorked = 0;

        foreach ($allSections as $code => $section) {
            $this->sectionData[$code] = [
                'name' => $section->name,
                'count' => 0,
                'bands' => [],
                'modes' => [],
            ];
        }

        if ($eventConfigId) {
            foreach ($this->sectionTallies($eventConfigId) as $code => $tally) {
                $this->sectionData[$code] = [
                    'name' => $this->sectionData[$code]['name'] ?? $code,
                    'count' => $tally['count'],
                    'bands' => $tally['bands'],
                    'modes' => $tally['modes'],
                    'latestBand' => $tally['latestBand'],
                    'latestQsoTime' => $tally['latestQsoTime'],
                ];

                $this->totalQsos += $tally['count'];
                $this->sectionsWorked++;
            }
        }

        $this->maxCount = max(array_column($this->sectionData, 'count') ?: [0]);
    }

    /**
     * Per-section QSO tallies from one GROUP BY over (section, band, mode),
     * so each poll reads a few rows per section instead of every contact.
     *
     * @return array<string, array{count: int, bands: list<string>, modes: list<string>, latestBand: ?string, latestQsoTime: ?int}>
     */
    private function sectionTallies(int $eventConfigId): array
    {
        $rows = Contact::query()
            ->forEvent($eventConfigId)
            ->notDuplicate()
            ->whereNotNull('section_id')
            ->groupBy('section_id', 'band_id', 'mode_id')
            ->selectRaw('section_id, band_id, mode_id, COUNT(*) as qso_count, MAX(qso_time) as latest_qso_time')
            ->toBase()
            ->get();

        $sectionCodes = Section::whereIn('id', $rows->pluck('section_id')->unique())->pluck('code', 'id');
        $bandNames = Band::whereIn('id', $rows->pluck('band_id')->filter()->unique())->pluck('name', 'id');
        $modeNames = Mode::whereIn('id', $rows->pluck('mode_id')->filter()->unique())->pluck('name', 'id');

        $tallies = [];

        foreach ($rows->groupBy(fn ($row) => EventSections::countedCode($sectionCodes[$row->section_id] ?? '', $this->event)) as $code => $sectionRows) {
            $latest = $sectionRows->sortByDesc('latest_qso_time')->first();

            $tallies[$code] = [
                'count' => (int) $sectionRows->sum('qso_count'),
                'bands' => $sectionRows->map(fn ($row) => $bandNames[$row->band_id] ?? null)->filter()->unique()->sort()->values()->all(),
                'modes' => $sectionRows->map(fn ($row) => $modeNames[$row->mode_id] ?? null)->filter()->unique()->sort()->values()->all(),
                'latestBand' => $bandNames[$latest->band_id] ?? null,
                'latestQsoTime' => $latest->latest_qso_time !== null ? Carbon::parse($latest->latest_qso_time)->timestamp : null,
            ];
        }

        return $tallies;
    }
}
