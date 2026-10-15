<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Section;
use App\Services\EventContextService;
use App\Support\EventSections;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class SectionsWorked extends Component
{
    use IsWidget;

    private const AREA_ORDER = [
        'W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'W8', 'W9', 'W0',
        ['KH6', 'KL7', 'KP4'],
        'VE',
        'DX',
    ];

    private const AREA_LABELS = [
        'W1' => 'W1', 'W2' => 'W2', 'W3' => 'W3', 'W4' => 'W4', 'W5' => 'W5',
        'W6' => 'W6', 'W7' => 'W7', 'W8' => 'W8', 'W9' => 'W9', 'W0' => 'W0',
        'VE' => 'VE', 'DX' => 'DX',
    ];

    public function getData(): array
    {
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();

        if (! $event?->eventConfiguration) {
            return $this->emptyData();
        }

        if ($this->shouldCache()) {
            return Cache::remember(
                $this->cacheKey(),
                now()->addSeconds(3),
                fn () => $this->buildSectionData($event)
            );
        }

        return $this->buildSectionData($event);
    }

    public function getWidgetListeners(): array
    {
        return $this->contactLoggedListener();
    }

    protected function buildSectionData(Event $event): array
    {
        $allSections = Section::where('is_active', true)
            ->whereNotIn('code', EventSections::foldedCodes($event))
            ->orderBy('code')
            ->get();

        $workedSectionIds = Contact::where('event_configuration_id', $event->eventConfiguration->id)
            ->notDuplicate()
            ->whereNotNull('section_id')
            ->distinct()
            ->pluck('section_id');

        $workedCodes = Section::whereIn('id', $workedSectionIds)
            ->pluck('code')
            ->map(fn (string $code) => EventSections::countedCode($code, $event))
            ->flip();

        $sectionsByRegion = $allSections->groupBy('region');
        $groups = [];

        foreach (self::AREA_ORDER as $area) {
            if (is_array($area)) {
                $combinedSections = collect();
                foreach ($area as $region) {
                    $combinedSections = $combinedSections->merge(
                        $sectionsByRegion->get($region, collect())
                    );
                }

                $sections = $combinedSections->map(fn (Section $s) => [
                    'code' => $s->code,
                    'name' => $s->name,
                    'worked' => $workedCodes->has($s->code),
                ])->values()->all();

                $workedCount = collect($sections)->where('worked', true)->count();

                $groups[] = [
                    'label' => 'KH6 / KL7 / KP4',
                    'sections' => $sections,
                    'worked_count' => $workedCount,
                    'total_count' => count($sections),
                ];
            } else {
                $regionSections = $sectionsByRegion->get($area, collect());

                $sections = $regionSections->map(fn (Section $s) => [
                    'code' => $s->code,
                    'name' => $s->name,
                    'worked' => $workedCodes->has($s->code),
                ])->values()->all();

                $workedCount = collect($sections)->where('worked', true)->count();

                $groups[] = [
                    'label' => self::AREA_LABELS[$area] ?? $area,
                    'sections' => $sections,
                    'worked_count' => $workedCount,
                    'total_count' => count($sections),
                ];
            }
        }

        $totalWorked = collect($groups)->sum('worked_count');
        $totalSections = collect($groups)->sum('total_count');

        return [
            'groups' => $groups,
            'total_worked' => $totalWorked,
            'total_sections' => $totalSections,
        ];
    }

    protected function emptyData(): array
    {
        return [
            'groups' => [],
            'total_worked' => 0,
            'total_sections' => 0,
        ];
    }

    public function render()
    {
        return view('livewire.dashboard.widgets.sections-worked', [
            'data' => $this->getData(),
        ]);
    }
}
