<div class="min-h-screen" style="background-color: var(--score-bg); color: var(--score-text);">

    {{-- EMPTY STATE --}}
    @if (! $this->event)
        <div class="flex flex-col items-center justify-center min-h-[60vh] gap-4">
            <x-mary-icon name="o-trophy" class="w-16 h-16 opacity-30" />
            <div class="text-2xl font-semibold opacity-50">No active event</div>
            <p class="text-sm opacity-40">Scores will appear here during an active Field Day event.</p>
        </div>
    @else

    {{-- ZONE 1: MASTHEAD --}}
    <div class="px-6 pt-8 pb-4" style="border-bottom: 2px solid var(--score-divider);">
        <div class="text-center font-black tracking-[0.25em] uppercase"
             style="font-size: clamp(2rem, 6vw, 4rem); color: var(--score-text);">
            {{ $this->event->eventConfiguration->callsign }}
        </div>
        <div class="text-center text-sm tracking-widest uppercase mt-2 font-medium"
             style="color: var(--score-text-muted); border-top: 1px solid var(--score-divider); border-bottom: 1px solid var(--score-divider); padding: 0.375rem 0; margin-top: 0.5rem;">
            {{ $this->event->eventConfiguration->operatingClass?->name ?? 'Unknown Class' }}
            &nbsp;·&nbsp;
            {{ $this->event->eventConfiguration->section?->name ?? 'Unknown Section' }}
            &nbsp;·&nbsp;
            {{ $this->event->eventConfiguration->transmitter_count }}
            {{ Str::plural('Transmitter', $this->event->eventConfiguration->transmitter_count) }}
            &nbsp;·&nbsp;
            {{ $this->event->start_time->format('M j, Y') }}
        </div>
        @if ($this->event->eventConfiguration->club_name)
            <div class="text-center text-xs tracking-wider mt-1" style="color: var(--score-text-muted);">
                {{ $this->event->eventConfiguration->club_name }}
            </div>
        @endif
    </div>

    {{-- ZONE 2: HEADLINE EQUATION --}}
    <div class="px-6 py-10" style="border-bottom: 2px solid var(--score-divider);">
        <div class="flex flex-wrap items-center justify-center gap-2 md:gap-4">

            <span class="text-4xl md:text-5xl font-light select-none" style="color: var(--score-text-muted);">(</span>

            <a href="#col-qso" class="text-center group no-underline">
                <div class="font-black tabular-nums transition-opacity group-hover:opacity-75"
                     style="font-size: clamp(2.5rem, 5vw, 4rem); color: var(--score-headline);">
                    {{ number_format($this->qsoBasePoints) }}
                </div>
                <div class="text-xs uppercase tracking-widest mt-1" style="color: var(--score-text-muted);">
                    QSO Base Pts
                </div>
            </a>

            <span class="text-3xl md:text-4xl font-light select-none" style="color: var(--score-text-muted);">×</span>

            <a href="#col-power" class="text-center group no-underline">
                <div class="font-black tabular-nums transition-opacity group-hover:opacity-75"
                     style="font-size: clamp(2.5rem, 5vw, 4rem); color: var(--score-headline);">
                    {{ $this->powerMultiplier }}×
                </div>
                <div class="text-xs uppercase tracking-widest mt-1" style="color: var(--score-text-muted);">
                    Power Multi.
                </div>
            </a>

            <span class="text-4xl md:text-5xl font-light select-none" style="color: var(--score-text-muted);">)</span>
            <span class="text-3xl md:text-4xl font-light select-none" style="color: var(--score-text-muted);">+</span>

            <a href="#col-bonus" class="text-center group no-underline">
                <div class="font-black tabular-nums transition-opacity group-hover:opacity-75"
                     style="font-size: clamp(2.5rem, 5vw, 4rem); color: var(--score-headline);">
                    {{ number_format($this->bonusScore) }}
                </div>
                <div class="text-xs uppercase tracking-widest mt-1" style="color: var(--score-text-muted);">
                    Bonus Pts
                </div>
            </a>

            <span class="text-3xl md:text-4xl font-light select-none" style="color: var(--score-text-muted);">=</span>

            <div class="text-center">
                <div class="font-black tabular-nums"
                     style="font-size: clamp(3.5rem, 8vw, 6rem); color: var(--score-headline-lg); line-height: 1;">
                    {{ number_format($this->finalScore) }}
                </div>
                <div class="text-xs uppercase tracking-widest mt-1 font-bold" style="color: var(--score-text-muted);">
                    Final Score
                </div>
            </div>

        </div>
    </div>

    {{-- ZONE 3: THREE COLUMNS --}}
    <div class="grid grid-cols-1 md:grid-cols-3" style="border-bottom: 1px solid var(--score-divider);">

        {{-- Column 1: QSO Points --}}
        <div id="col-qso" class="p-6 md:border-r" style="border-color: var(--score-border);">
            <div class="text-xs font-bold uppercase tracking-widest mb-4" style="color: var(--score-text-muted);">
                QSO Points
            </div>

            {{-- Scoring key --}}
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach ([['CW', '2 pts'], ['Phone', '1 pt'], ['Digital', '2 pts']] as [$modeName, $pts])
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium"
                          style="background: var(--score-surface-alt); color: var(--score-text);">
                        {{ $modeName }} = {{ $pts }}
                    </span>
                @endforeach
            </div>

            {{-- Band/Mode Grid --}}
            @if (count($this->bandModeGrid) > 0 && collect($this->bandModeGrid)->sum('total_count') > 0)
                <div class="overflow-x-auto mb-4">
                    <table class="w-full text-xs">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--score-divider);">
                                <th class="text-left pb-2 font-semibold uppercase tracking-wide pr-2"
                                    style="color: var(--score-text-muted);">Mode</th>
                                @foreach ($this->bands as $band)
                                    <th class="text-center pb-2 font-semibold uppercase tracking-wide px-1"
                                        style="color: var(--score-text-muted); white-space: nowrap;">
                                        {{ $band->name }}
                                    </th>
                                @endforeach
                                <th class="text-right pb-2 font-semibold uppercase tracking-wide pl-2"
                                    style="color: var(--score-text-muted);">QSOs</th>
                                <th class="text-right pb-2 font-semibold uppercase tracking-wide pl-2"
                                    style="color: var(--score-text-muted);">Pts</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->bandModeGrid as $row)
                                <tr style="border-bottom: 1px solid var(--score-divider);">
                                    <td class="py-2 pr-2 font-semibold" style="color: var(--score-text);">
                                        {{ $row['mode']->name }}
                                    </td>
                                    @foreach ($this->bands as $band)
                                        <td class="py-2 text-center px-1 tabular-nums">
                                            @if ($row['cells'][$band->id] > 0)
                                                <span class="font-bold" style="color: var(--score-headline);">
                                                    {{ $row['cells'][$band->id] }}
                                                </span>
                                            @else
                                                <span style="color: var(--score-text-muted); opacity: 0.4;">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="py-2 text-right pl-2 font-bold tabular-nums"
                                        style="color: {{ $row['total_count'] > 0 ? 'var(--score-text)' : 'var(--score-text-muted)' }};">
                                        {{ $row['total_count'] ?: '—' }}
                                    </td>
                                    <td class="py-2 text-right pl-2 tabular-nums"
                                        style="color: var(--score-text-muted);">
                                        {{ $row['total_points'] ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        @php
                            $bandTotals = [];
                            $grandTotalCount = 0;
                            $grandTotalPoints = 0;
                            foreach ($this->bandModeGrid as $row) {
                                foreach ($this->bands as $band) {
                                    $bandTotals[$band->id] = ($bandTotals[$band->id] ?? 0) + ($row['cells'][$band->id] ?? 0);
                                }
                                $grandTotalCount += $row['total_count'];
                                $grandTotalPoints += $row['total_points'];
                            }
                        @endphp
                        <tfoot>
                            <tr class="font-bold border-t" style="border-color: var(--score-divider);">
                                <td class="pt-2 pr-2" style="color: var(--score-text);">Total</td>
                                @foreach ($this->bands as $band)
                                    <td class="pt-2 text-center px-1 tabular-nums" style="color: var(--score-text);">
                                        {{ $bandTotals[$band->id] ?? 0 ?: '—' }}
                                    </td>
                                @endforeach
                                <td class="pt-2 text-right pl-2 tabular-nums" style="color: var(--score-text);">
                                    {{ $grandTotalCount }}
                                </td>
                                <td class="pt-2 text-right pl-2 tabular-nums" style="color: var(--score-text);">
                                    {{ $grandTotalPoints }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <div class="text-sm py-4 text-center mb-4" style="color: var(--score-text-muted);">
                    No contacts logged yet.
                </div>
            @endif

            {{-- Contact summary stats --}}
            <div class="grid grid-cols-2 gap-3 mt-2" style="border-top: 1px solid var(--score-divider); padding-top: 1rem;">
                <div>
                    <div class="text-xs uppercase tracking-wide" style="color: var(--score-text-muted);">Total Logged</div>
                    <div class="text-2xl font-bold tabular-nums mt-0.5" style="color: var(--score-text);">
                        {{ number_format($this->totalContacts) }}
                    </div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide" style="color: var(--score-text-muted);">Valid QSOs</div>
                    <div class="text-2xl font-bold tabular-nums mt-0.5" style="color: var(--score-headline);">
                        {{ number_format($this->validContacts) }}
                    </div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide" style="color: var(--score-text-muted);">Duplicates</div>
                    <div class="text-xl font-semibold tabular-nums mt-0.5"
                         style="color: {{ $this->duplicateCount > 0 ? 'var(--score-warning)' : 'var(--score-text-muted)' }};">
                        {{ $this->duplicateCount }}
                        <span class="text-sm font-normal">({{ number_format($this->duplicateRate, 1) }}%)</span>
                    </div>
                </div>
                @if ($this->gotaContactCount > 0)
                    <div>
                        <div class="text-xs uppercase tracking-wide" style="color: var(--score-text-muted);">GOTA Contacts</div>
                        <div class="text-xl font-semibold tabular-nums mt-0.5" style="color: var(--score-text);">
                            {{ $this->gotaContactCount }}
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Column 2: Power Multiplier --}}
        <div id="col-power" class="p-6 md:border-r" style="border-color: var(--score-border);">
            <div class="text-xs font-bold uppercase tracking-widest mb-4" style="color: var(--score-text-muted);">
                Power Multiplier
            </div>
            <div class="text-sm opacity-50" style="color: var(--score-text-muted);">Coming soon…</div>
        </div>

        {{-- Column 3: Bonus Points --}}
        <div id="col-bonus" class="p-6">
            <div class="text-xs font-bold uppercase tracking-widest mb-4" style="color: var(--score-text-muted);">
                Bonus Points
            </div>
            <div class="text-sm opacity-50" style="color: var(--score-text-muted);">Coming soon…</div>
        </div>

    </div>

    {{-- ZONE 4: CORRECTIONS (placeholder — added in Task 8) --}}

    @endif
</div>
