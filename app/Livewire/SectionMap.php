<?php

namespace App\Livewire;

use App\Models\Contact;
use App\Models\Section;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SectionMap extends Component
{
    public function render(): View
    {
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();
        $eventConfigId = $event?->eventConfiguration?->id;

        $allSections = Section::where('is_active', true)
            ->orderBy('code')
            ->get()
            ->keyBy('code');

        $sectionData = [];
        $totalQsos = 0;
        $sectionsWorked = 0;

        foreach ($allSections as $code => $section) {
            $sectionData[$code] = [
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

            $grouped = $contacts->groupBy(fn ($c) => $c->section->code);

            foreach ($grouped as $code => $sectionContacts) {
                $count = $sectionContacts->count();
                $bands = $sectionContacts->pluck('band.name')->filter()->unique()->sort()->values()->all();
                $modes = $sectionContacts->pluck('mode.name')->filter()->unique()->sort()->values()->all();
                $bandCounts = $sectionContacts->groupBy(fn ($c) => $c->band?->name)
                    ->filter(fn ($group, $key) => $key !== null && $key !== '')
                    ->mapWithKeys(fn ($group, $name) => [$name => $group->count()])
                    ->all();
                $latestQsoTime = $sectionContacts->max('qso_time')?->timestamp;

                $sectionData[$code] = [
                    'name' => $sectionData[$code]['name'] ?? $code,
                    'count' => $count,
                    'bands' => $bands,
                    'modes' => $modes,
                    'bandCounts' => $bandCounts,
                    'latestQsoTime' => $latestQsoTime,
                ];

                $totalQsos += $count;
                $sectionsWorked++;
            }

        }

        $maxCount = max(array_column($sectionData, 'count') ?: [0]);

        return view('livewire.section-map', [
            'sectionData' => $sectionData,
            'maxCount' => $maxCount,
            'totalQsos' => $totalQsos,
            'sectionsWorked' => $sectionsWorked,
            'totalSections' => $allSections->count(),
            'hasEvent' => $eventConfigId !== null,
        ]);
    }
}
