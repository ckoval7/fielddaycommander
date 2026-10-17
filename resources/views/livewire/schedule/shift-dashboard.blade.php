<div wire:poll.visible.60s>
    <x-slot:title>Shift Dashboard{{ $event ? ' - ' . $event->name : '' }}</x-slot:title>

    <div class="p-4 md:p-6 max-sm:px-0">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold">Shift Dashboard</h1>
                @if($event)
                    <p class="text-base-content/60">{{ $event->name }}</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <x-button
                    label="Shift Schedule"
                    icon="phosphor-list-bullets"
                    class="btn-outline"
                    link="{{ route('schedule.index') }}"
                />
                @can('manage-shifts')
                    <x-button
                        label="Manage Schedule"
                        icon="phosphor-gear-six"
                        class="btn-outline"
                        link="{{ route('schedule.manage') }}"
                    />
                @endcan
            </div>
        </div>

        @php $coverage = $this->coverage; @endphp

        @if(!$eventConfig)
            <x-alert icon="phosphor-info" class="alert-info">
                No event is currently selected. Please select an event to view the shift dashboard.
            </x-alert>
        @elseif(!$coverage)
            <div class="text-center py-12">
                <x-icon name="phosphor-calendar" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
                <h3 class="text-lg font-semibold mb-2">No shifts scheduled</h3>
                <p class="text-base-content/60">Shifts have not been set up for this event yet.</p>
            </div>
        @else
            @php
                $summary = $coverage->summary();
                $gaps = collect($coverage->gaps())->whereIn('type', [\App\Support\ShiftCoverage::GAP_UNSTAFFED, \App\Support\ShiftCoverage::GAP_UNDERSTAFFED]);
                $now = appNow();
                $activeGaps = $gaps->filter(fn ($gap) => $gap['end']->greaterThan($now))->values();
                $pastGaps = $gaps->filter(fn ($gap) => $gap['end']->lessThanOrEqualTo($now))->values();
                $formatDuration = function (int $minutes): string {
                    $hours = intdiv($minutes, 60);
                    $remaining = $minutes % 60;

                    return trim(($hours ? $hours . 'h ' : '') . ($remaining ? $remaining . 'm' : '')) ?: '0m';
                };
                $formatRange = function ($start, $end): string {
                    $startLocal = toLocalTime($start);
                    $endLocal = toLocalTime($end);
                    $spansDays = $startLocal->format('Y-m-d') !== $endLocal->format('Y-m-d');

                    return $startLocal->format('D ' . timeFormat()) . ' – ' . $endLocal->format(($spansDays ? 'D ' : '') . timeFormat() . ' T');
                };
            @endphp

            {{-- Gantt Card --}}
            @php
                $ticks = $coverage->hourTicks();
                $windowHours = max(1, $coverage->windowStart->diffInHours($coverage->windowEnd));
                $nowInWindow = $now->between($coverage->windowStart, $coverage->windowEnd);
                $nowOffset = $coverage->offsetPercent($now);
                $laneHeight = 28;
                $canManageShifts = auth()->user()?->can('manage-shifts') ?? false;
            @endphp
            <x-card title="Timeline" shadow class="mb-6" data-testid="gantt-card">
                <x-slot:menu>
                    <div class="hidden sm:flex items-center gap-3 text-xs text-base-content/60">
                        <span class="flex items-center gap-1"><span class="inline-block w-4 h-3 rounded-sm bg-base-content/70"></span>Full</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-4 h-3 rounded-sm bg-base-content/35"></span>Partial</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-4 h-3 rounded-sm border border-dashed border-base-content/70"></span>Empty</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-4 h-3 rounded-sm gantt-gap"></span>Gap</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-4 h-3 rounded-sm gantt-gap-partial"></span>Under half</span>
                    </div>
                </x-slot:menu>

                <div class="overflow-x-auto">
                    <div style="min-width: max(100%, {{ 9 + $windowHours * 2.5 }}rem);">
                        {{-- Time axis --}}
                        <div class="grid grid-cols-[6rem_1fr] sm:grid-cols-[9rem_1fr] border-b border-base-300">
                            <div></div>
                            <div class="relative h-10">
                                @php $previousTickDay = null; @endphp
                                @foreach($ticks as $tick)
                                    @if($tick['labelled'])
                                        @php
                                            $tickLocal = toLocalTime($tick['time']);
                                            $showDay = $tickLocal->format('Y-m-d') !== $previousTickDay;
                                            $previousTickDay = $tickLocal->format('Y-m-d');
                                        @endphp
                                        <div class="absolute bottom-0 -translate-x-1/2 text-[10px] leading-tight text-center text-base-content/60 whitespace-nowrap" style="left: {{ $tick['offset'] }}%">
                                            @if($showDay)
                                                <div class="font-semibold">{{ $tickLocal->format('D') }}</div>
                                            @else
                                                <div>&nbsp;</div>
                                            @endif
                                            <div>{{ $tickLocal->format(timeFormat() === 'H:i' ? 'H:i' : 'ga') }}</div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>

                        {{-- Role rows --}}
                        @foreach($coverage->ganttRows() as $row)
                            @php $laneCount = max(1, count($row['lanes'])); @endphp
                            <div class="grid grid-cols-[6rem_1fr] sm:grid-cols-[9rem_1fr] border-b border-base-300/60" data-testid="gantt-row">
                                <div class="flex items-center gap-1 pr-2 py-1 text-sm min-w-0">
                                    <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $row['role']->color ?? '#64748b' }}"></span>
                                    <span class="truncate" title="{{ $row['role']->name }}">{{ $row['role']->name }}</span>
                                </div>
                                <div class="relative" style="height: {{ $laneCount * $laneHeight + 8 }}px">
                                    {{-- Hour gridlines --}}
                                    @foreach($ticks as $tick)
                                        <div class="absolute inset-y-0 border-l {{ $tick['labelled'] ? 'border-base-300' : 'border-base-300/40' }}" style="left: {{ $tick['offset'] }}%"></div>
                                    @endforeach

                                    {{-- Coverage gaps --}}
                                    @foreach($row['gaps'] as $gap)
                                        <div
                                            class="absolute inset-y-0 {{ $gap['type'] === \App\Support\ShiftCoverage::GAP_UNDERSTAFFED ? 'gantt-gap-partial' : 'gantt-gap' }}"
                                            style="left: {{ $coverage->offsetPercent($gap['start']) }}%; width: {{ $coverage->widthPercent($gap['start'], $gap['end']) }}%"
                                            title="{{ match ($gap['type']) { \App\Support\ShiftCoverage::GAP_UNSTAFFED => 'Unstaffed', \App\Support\ShiftCoverage::GAP_UNDERSTAFFED => 'Under half staffed', default => 'Unscheduled' } }}: {{ $formatRange($gap['start'], $gap['end']) }}"
                                        ></div>
                                    @endforeach

                                    {{-- Shift bars --}}
                                    @foreach($row['lanes'] as $laneIndex => $lane)
                                        @foreach($lane as $shift)
                                            @php
                                                $staffed = \App\Support\ShiftCoverage::staffedCount($shift);
                                                $barColor = $row['role']->color ?? '#64748b';
                                                $names = $shift->assignments
                                                    ->where('status', '!=', \App\Models\ShiftAssignment::STATUS_NO_SHOW)
                                                    ->map(fn ($assignment) => $assignment->user?->call_sign ?: $assignment->user?->first_name)
                                                    ->filter()
                                                    ->implode(', ');
                                                $barStyle = match (true) {
                                                    $staffed >= $shift->capacity => "background-color: {$barColor}; color: white;",
                                                    $staffed > 0 => "background-color: color-mix(in oklab, {$barColor} 45%, transparent); border: 1px solid {$barColor};",
                                                    default => "border: 1px dashed {$barColor}; background-color: var(--color-base-100);",
                                                };
                                                $canAssign = $canManageShifts && $staffed < $shift->capacity;
                                            @endphp
                                            <div
                                                @class([
                                                    'absolute flex items-center gap-1 px-1.5 rounded text-[11px] leading-none overflow-hidden whitespace-nowrap',
                                                    'opacity-50' => $shift->is_past,
                                                    'cursor-pointer hover:ring-2 hover:ring-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary' => $canAssign,
                                                ])
                                                @if($canAssign)
                                                    role="button"
                                                    tabindex="0"
                                                    wire:click="openAssignModal({{ $shift->id }})"
                                                    x-on:keydown.enter="$wire.openAssignModal({{ $shift->id }})"
                                                    data-assignable
                                                @endif
                                                style="left: {{ $coverage->offsetPercent($shift->start_time) }}%; width: {{ $coverage->widthPercent($shift->start_time, $shift->end_time) }}%; top: {{ $laneIndex * $laneHeight + 4 }}px; height: {{ $laneHeight - 6 }}px; {{ $barStyle }}"
                                                title="{{ $row['role']->name }} · {{ $formatRange($shift->start_time, $shift->end_time) }} · {{ $staffed }}/{{ $shift->capacity }} filled{{ $names ? ' · ' . $names : '' }}{{ $canAssign ? ' · Click to assign someone' : '' }}"
                                                data-testid="gantt-bar"
                                            >
                                                <span class="font-semibold">{{ $staffed }}/{{ $shift->capacity }}</span>
                                                @if($names)
                                                    <span class="truncate">{{ $names }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    @endforeach

                                    {{-- Now marker --}}
                                    @if($nowInWindow)
                                        <div class="absolute inset-y-0 border-l-2 border-error z-10 pointer-events-none" style="left: {{ $nowOffset }}%"></div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-card>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6" wire:loading.class="opacity-50">
                {{-- Coverage Card --}}
                <x-card title="Coverage" shadow data-testid="coverage-card">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-6 mb-6">
                        <div
                            class="radial-progress shrink-0 mx-auto sm:mx-0 {{ $summary['coverage_percent'] >= 80 ? 'text-success' : ($summary['coverage_percent'] >= 50 ? 'text-warning' : 'text-error') }}"
                            style="--value: {{ $summary['coverage_percent'] }}; --size: 7rem; --thickness: 0.6rem;"
                            aria-hidden="true"
                        >
                            <span class="text-2xl font-bold text-base-content">{{ $summary['coverage_percent'] }}%</span>
                        </div>
                        <progress class="sr-only" value="{{ $summary['coverage_percent'] }}" max="100" aria-label="Overall shift coverage">{{ $summary['coverage_percent'] }}%</progress>

                        <div class="grid grid-cols-2 gap-3 flex-1">
                            <div class="rounded-lg bg-base-200/50 p-3">
                                <div class="text-xs text-base-content/60">Filled slots</div>
                                <div class="text-lg font-semibold">{{ $summary['filled_slots'] }} / {{ $summary['total_slots'] }}</div>
                            </div>
                            <div class="rounded-lg bg-base-200/50 p-3">
                                <div class="text-xs text-base-content/60">Open slots</div>
                                <div class="text-lg font-semibold {{ $summary['open_slots'] > 0 ? 'text-warning' : '' }}">{{ $summary['open_slots'] }}</div>
                            </div>
                            <div class="rounded-lg bg-base-200/50 p-3">
                                <div class="text-xs text-base-content/60">Fully staffed shifts</div>
                                <div class="text-lg font-semibold">{{ $summary['full_shifts'] }} / {{ $summary['shift_count'] }}</div>
                            </div>
                            <div class="rounded-lg bg-base-200/50 p-3">
                                <div class="text-xs text-base-content/60">On duty now</div>
                                <div class="text-lg font-semibold">{{ $summary['on_duty'] }}</div>
                            </div>
                        </div>
                    </div>

                    <p class="text-xs text-base-content/60 mb-3">Coverage by role, weighted by shift length</p>
                    <div class="space-y-3">
                        @foreach($coverage->roleCoverage() as $roleCoverage)
                            <div>
                                <div class="flex items-center justify-between gap-2 text-sm mb-1">
                                    <span class="flex items-center gap-1 min-w-0">
                                        <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $roleCoverage['role']->color ?? '#64748b' }}"></span>
                                        <span class="truncate">{{ $roleCoverage['role']->name }}</span>
                                    </span>
                                    <span class="text-base-content/60 shrink-0">
                                        {{ $roleCoverage['filled_slots'] }}/{{ $roleCoverage['total_slots'] }} slots · {{ $roleCoverage['coverage_percent'] }}%
                                    </span>
                                </div>
                                <div class="h-2 rounded-full bg-base-300 overflow-hidden">
                                    <div class="h-full rounded-full" style="width: {{ $roleCoverage['coverage_percent'] }}%; background-color: {{ $roleCoverage['role']->color ?? '#64748b' }}"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>

                {{-- Gaps Card --}}
                <x-card title="Coverage Gaps" shadow data-testid="gaps-card" x-data="{ showPast: false }">
                    <x-slot:menu>
                        <x-badge :value="$activeGaps->count() . ' open'" :class="$activeGaps->isEmpty() ? 'badge-success badge-sm' : 'badge-error badge-sm'" />
                    </x-slot:menu>

                    <p class="text-xs text-base-content/60 mb-3">
                        Shifts with nobody signed up, or less than half filled.
                    </p>

                    @if($activeGaps->isEmpty())
                        <div class="text-center py-8">
                            <x-icon name="phosphor-check-circle" class="w-12 h-12 mx-auto mb-2 text-success" />
                            <p class="font-medium">No upcoming coverage gaps</p>
                        </div>
                    @else
                        <ul class="space-y-2 max-h-96 overflow-y-auto">
                            @foreach($activeGaps as $gap)
                                @php
                                    $isCurrent = $gap['start']->lessThanOrEqualTo($now);
                                    $gapShift = $canManageShifts ? collect($gap['shifts'])->first() : null;
                                @endphp
                                <li
                                    @class([
                                        'flex items-center gap-3 p-2 rounded-lg border',
                                        'border-error bg-error/5' => $isCurrent,
                                        'border-base-300 bg-base-200/50' => ! $isCurrent,
                                        'cursor-pointer hover:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary' => $gapShift,
                                    ])
                                    @if($gapShift)
                                        role="button"
                                        tabindex="0"
                                        title="Click to assign someone"
                                        wire:click="openAssignModal({{ $gapShift->id }})"
                                        x-on:keydown.enter="$wire.openAssignModal({{ $gapShift->id }})"
                                        data-assignable
                                    @endif
                                >
                                    <span class="inline-block w-1 self-stretch rounded-full shrink-0" style="background-color: {{ $gap['role']->color ?? '#64748b' }}"></span>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium truncate">{{ $gap['role']->name }}</div>
                                        <div class="text-xs text-base-content/60">{{ $formatRange($gap['start'], $gap['end']) }}</div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1 shrink-0">
                                        <span class="text-sm font-semibold">{{ $formatDuration($gap['minutes']) }}</span>
                                        <div class="flex gap-1">
                                            @if($isCurrent)
                                                <x-badge value="Now" class="badge-error badge-xs" />
                                            @endif
                                            @if($gap['type'] === \App\Support\ShiftCoverage::GAP_UNSTAFFED)
                                                <x-badge value="Empty" class="badge-error badge-outline badge-xs" />
                                            @else
                                                <x-badge value="Under half" class="badge-warning badge-outline badge-xs" />
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if($pastGaps->isNotEmpty())
                        <button type="button" class="btn btn-ghost btn-xs mt-3" @click="showPast = !showPast">
                            <span x-text="showPast ? 'Hide past gaps' : 'Show past gaps ({{ $pastGaps->count() }})'"></span>
                        </button>
                        <ul x-show="showPast" x-cloak class="space-y-1 mt-2 text-base-content/60">
                            @foreach($pastGaps as $gap)
                                <li class="flex items-center justify-between gap-2 text-xs">
                                    <span class="truncate">{{ $gap['role']->name }} · {{ $formatRange($gap['start'], $gap['end']) }}</span>
                                    <span class="shrink-0">{{ $formatDuration($gap['minutes']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            </div>
        @endif
    </div>

    <style>
        .gantt-gap {
            background-image: repeating-linear-gradient(135deg, color-mix(in oklab, var(--color-error) 22%, transparent) 0 4px, transparent 4px 8px);
        }
        .gantt-gap-partial {
            background-image: repeating-linear-gradient(135deg, color-mix(in oklab, var(--color-warning) 30%, transparent) 0 4px, transparent 4px 8px);
        }
    </style>
    @can('manage-shifts')
        @include('livewire.schedule.partials.assign-modal')
    @endcan
</div>
