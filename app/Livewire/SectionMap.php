<?php

namespace App\Livewire;

use App\Models\Contact;
use App\Models\Event;
use App\Models\Section;
use App\Services\EventContextService;
use App\Support\BroadcastChannels;
use App\Support\EventSections;
use Illuminate\Contracts\View\View;
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

        return [
            BroadcastChannels::privateListener(BroadcastChannels::event($this->event->id), '.ContactLogged') => 'handleContactLogged',
        ];
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
            $contacts = Contact::query()
                ->forEvent($eventConfigId)
                ->notDuplicate()
                ->whereNotNull('section_id')
                ->with(['band', 'mode', 'section'])
                ->get();

            $grouped = $contacts->groupBy(fn ($c) => EventSections::countedCode($c->section->code, $this->event));

            foreach ($grouped as $code => $sectionContacts) {
                $count = $sectionContacts->count();
                $bands = $sectionContacts->pluck('band.name')->filter()->unique()->sort()->values()->all();
                $modes = $sectionContacts->pluck('mode.name')->filter()->unique()->sort()->values()->all();
                $latestContact = $sectionContacts->sortByDesc('qso_time')->first();
                $latestBand = $latestContact?->band?->name;
                $latestQsoTime = $latestContact?->qso_time?->timestamp;

                $this->sectionData[$code] = [
                    'name' => $this->sectionData[$code]['name'] ?? $code,
                    'count' => $count,
                    'bands' => $bands,
                    'modes' => $modes,
                    'latestBand' => $latestBand,
                    'latestQsoTime' => $latestQsoTime,
                ];

                $this->totalQsos += $count;
                $this->sectionsWorked++;
            }
        }

        $this->maxCount = max(array_column($this->sectionData, 'count') ?: [0]);
    }
}
