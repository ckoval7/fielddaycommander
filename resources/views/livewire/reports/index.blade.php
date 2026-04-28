{{-- ============================================================
     Reports Hub — Page-scoped color system
     Named --reports-* to avoid collisions with scoring tokens
     ============================================================ --}}
<style>
    :root {
        --reports-bg:           hsl(215, 20%, 97%);
        --reports-surface:      hsl(0, 0%, 100%);
        --reports-surface-alt:  hsl(215, 16%, 94%);
        --reports-text:         hsl(215, 25%, 15%);
        --reports-text-muted:   hsl(215, 15%, 50%);
        --reports-border:       hsl(215, 20%, 86%);
        --reports-divider:      hsl(215, 20%, 91%);

        /* Cabrillo card — amber */
        --reports-cabrillo-border: hsl(38, 80%, 50%);
        --reports-cabrillo-bg:     hsl(38, 90%, 97%);
        --reports-cabrillo-icon:   hsl(38, 80%, 38%);

        /* PDF card — deep blue */
        --reports-pdf-border:  hsl(223, 71%, 40%);
        --reports-pdf-bg:      hsl(223, 90%, 97%);
        --reports-pdf-icon:    hsl(223, 71%, 40%);

        /* CSV card — neutral slate */
        --reports-csv-border:  hsl(215, 20%, 72%);
        --reports-csv-bg:      hsl(215, 20%, 97%);
        --reports-csv-icon:    hsl(215, 20%, 42%);

        /* Section header accent bar */
        --reports-section-bar: hsl(223, 71%, 40%);

        /* Highlight for active QSO rows */
        --reports-row-active:  hsl(38, 90%, 95%);
        --reports-row-hover:   hsl(215, 20%, 96%);

        --reports-warning:     hsl(38, 92%, 50%);
    }

    [data-theme="dark"] {
        --reports-bg:           hsl(222, 20%, 9%);
        --reports-surface:      hsl(222, 16%, 14%);
        --reports-surface-alt:  hsl(222, 14%, 18%);
        --reports-text:         hsl(210, 20%, 92%);
        --reports-text-muted:   hsl(215, 14%, 58%);
        --reports-border:       hsl(215, 16%, 24%);
        --reports-divider:      hsl(215, 14%, 20%);

        --reports-cabrillo-border: hsl(40, 82%, 52%);
        --reports-cabrillo-bg:     hsl(38, 30%, 12%);
        --reports-cabrillo-icon:   hsl(40, 86%, 58%);

        --reports-pdf-border:  hsl(217, 80%, 60%);
        --reports-pdf-bg:      hsl(223, 30%, 12%);
        --reports-pdf-icon:    hsl(217, 82%, 64%);

        --reports-csv-border:  hsl(215, 16%, 36%);
        --reports-csv-bg:      hsl(222, 14%, 16%);
        --reports-csv-icon:    hsl(215, 18%, 66%);

        --reports-section-bar: hsl(217, 80%, 58%);

        --reports-row-active:  hsl(38, 30%, 14%);
        --reports-row-hover:   hsl(222, 14%, 17%);

        --reports-warning:     hsl(38, 92%, 65%);
    }
</style>

<div class="min-h-screen" style="background-color: var(--reports-bg); color: var(--reports-text);">

    {{-- ============================================================
         EMPTY STATE — no active event
         ============================================================ --}}
    @if (! $this->event)
        <div class="flex flex-col items-center justify-center min-h-96 gap-4 p-8">
            <x-mary-icon name="phosphor-chart-bar" class="w-16 h-16 opacity-25" />
            <div class="text-2xl font-semibold opacity-50">No active event</div>
            <p class="text-sm text-center max-w-sm" style="color: var(--reports-text-muted);">
                Reports will be available once a Field Day event is active.
            </p>
        </div>
    @else

    {{-- ============================================================
         EVENT CONTEXT BAR
         One-line condensed identifier strip
         ============================================================ --}}
    <div class="px-6 py-3 flex flex-wrap items-center gap-x-4 gap-y-1"
         style="border-bottom: 2px solid var(--reports-border); background-color: var(--reports-surface);">

        <span class="font-black font-mono tracking-wider uppercase text-lg"
              style="color: var(--reports-text);">
            {{ $this->event->eventConfiguration->callsign }}
        </span>

        <span style="color: var(--reports-border);">|</span>

        <span class="text-sm font-semibold uppercase tracking-wide"
              style="color: var(--reports-text-muted);">
            {{ $this->event->eventConfiguration->operatingClass?->name ?? 'Unknown Class' }}
        </span>

        <span style="color: var(--reports-border);">·</span>

        <span class="text-sm font-semibold uppercase tracking-wide"
              style="color: var(--reports-text-muted);">
            {{ $this->event->eventConfiguration->section?->name ?? 'Unknown Section' }}
        </span>

        @if ($this->event->eventConfiguration->club_name)
            <span style="color: var(--reports-border);">·</span>
            <span class="text-sm" style="color: var(--reports-text-muted);">
                {{ $this->event->eventConfiguration->club_name }}
            </span>
        @endif

        <span class="ml-auto text-xs font-mono" style="color: var(--reports-text-muted);">
            {{ $this->event->start_time->format('M j, Y') }}
            &ndash;
            {{ $this->event->end_time->format('M j, Y') }}
        </span>
    </div>

    {{-- ============================================================
         SECTION 1: DOWNLOADS — primary action zone
         F-pattern top row — immediately visible, clearly actionable
         ============================================================ --}}
    <div class="px-6 py-8" style="border-bottom: 1px solid var(--reports-divider);">
        <div class="text-xs font-bold uppercase tracking-widest mb-5"
             style="color: var(--reports-text-muted); display: flex; align-items: center; gap: 0.5rem;">
            <span style="display: inline-block; width: 3px; height: 1em; background-color: var(--reports-section-bar); border-radius: 2px;"></span>
            Export &amp; Download
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

            {{-- CABRILLO LOG — amber, prominent CTA --}}
            <a href="{{ route('reports.cabrillo') }}"
               class="group no-underline block rounded-lg p-5 transition-shadow hover:shadow-md"
               style="background-color: var(--reports-cabrillo-bg); border: 2px solid var(--reports-cabrillo-border);">
                <div class="flex items-start gap-4">
                    <div class="shrink-0 mt-0.5 w-10 h-10 rounded-lg flex items-center justify-center"
                         style="background-color: var(--reports-cabrillo-border); color: white;">
                        <x-mary-icon name="phosphor-cell-signal-high" class="w-5 h-5" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-base leading-tight" style="color: var(--reports-text);">
                            Cabrillo Log
                        </div>
                        <div class="text-xs mt-1 leading-snug" style="color: var(--reports-text-muted);">
                            ARRL submission format · .cbr file
                        </div>
                        <div class="mt-3 text-xs font-semibold uppercase tracking-wide"
                             style="color: var(--reports-cabrillo-icon);">
                            Download &rarr;
                        </div>
                    </div>
                </div>
            </a>

            {{-- ARRL SUBMISSION SHEET — blue border --}}
            <a href="{{ route('reports.submission-sheet') }}"
               class="group no-underline block rounded-lg p-5 transition-shadow hover:shadow-md"
               style="background-color: var(--reports-pdf-bg); border: 2px solid var(--reports-pdf-border);">
                <div class="flex items-start gap-4">
                    <div class="shrink-0 mt-0.5 w-10 h-10 rounded-lg flex items-center justify-center"
                         style="background-color: var(--reports-pdf-border); color: white;">
                        <x-mary-icon name="phosphor-file-text" class="w-5 h-5" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-base leading-tight" style="color: var(--reports-text);">
                            Submission Sheet
                        </div>
                        <div class="text-xs mt-1 leading-snug" style="color: var(--reports-text-muted);">
                            ARRL entry form reference · .pdf with dupe sheet
                        </div>
                        <div class="mt-3 text-xs font-semibold uppercase tracking-wide"
                             style="color: var(--reports-pdf-icon);">
                            Download &rarr;
                        </div>
                    </div>
                </div>
            </a>

            {{-- CSV LOGBOOK — neutral --}}
            <a href="{{ route('logbook.export') }}"
               class="group no-underline block rounded-lg p-5 transition-shadow hover:shadow-md"
               style="background-color: var(--reports-csv-bg); border: 2px solid var(--reports-csv-border);">
                <div class="flex items-start gap-4">
                    <div class="shrink-0 mt-0.5 w-10 h-10 rounded-lg flex items-center justify-center"
                         style="background-color: var(--reports-csv-border); color: white;">
                        <x-mary-icon name="phosphor-squares-four" class="w-5 h-5" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-base leading-tight" style="color: var(--reports-text);">
                            Logbook CSV
                        </div>
                        <div class="text-xs mt-1 leading-snug" style="color: var(--reports-text-muted);">
                            Full contact log · spreadsheet format
                        </div>
                        <div class="mt-3 text-xs font-semibold uppercase tracking-wide"
                             style="color: var(--reports-csv-icon);">
                            Download &rarr;
                        </div>
                    </div>
                </div>
            </a>

        </div>
    </div>

    {{-- ============================================================
         SECTION 2: QSO RATE BY HOUR
         ============================================================ --}}
    <div class="px-6 py-8" style="border-bottom: 1px solid var(--reports-divider);">
        <div class="text-xs font-bold uppercase tracking-widest mb-5"
             style="color: var(--reports-text-muted); display: flex; align-items: center; gap: 0.5rem;">
            <span style="display: inline-block; width: 3px; height: 1em; background-color: var(--reports-section-bar); border-radius: 2px;"></span>
            QSO Rate by Hour
        </div>

        <div class="rounded-lg overflow-hidden"
             style="border: 1px solid var(--reports-border); background-color: var(--reports-surface);">
            @if (count($this->qsoRateByHour) === 0)
                <div class="flex flex-col items-center justify-center py-12 gap-3">
                    <x-mary-icon name="phosphor-clock" class="w-10 h-10 opacity-25" />
                    <p class="text-sm" style="color: var(--reports-text-muted);">No contacts logged yet.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--reports-border);">
                                <th class="text-left px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">Hour (UTC)</th>
                                <th class="text-right px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">Total</th>
                                <th class="text-right px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">CW</th>
                                <th class="text-right px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">Phone</th>
                                <th class="text-right px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">Digital</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->qsoRateByHour as $row)
                                <tr style="border-bottom: 1px solid var(--reports-divider);
                                           @if ($row['total'] > 0) background-color: var(--reports-row-active); @endif">
                                    <td class="px-4 py-2.5 font-mono text-xs"
                                        style="color: var(--reports-text);">
                                        {{ $row['hour'] }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono font-bold tabular-nums"
                                        style="color: {{ $row['total'] > 0 ? 'var(--reports-text)' : 'var(--reports-text-muted)' }};">
                                        {{ $row['total'] ?: '—' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono tabular-nums"
                                        style="color: {{ $row['cw'] > 0 ? 'var(--reports-text)' : 'var(--reports-text-muted)' }};">
                                        {{ $row['cw'] ?: '—' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono tabular-nums"
                                        style="color: {{ $row['phone'] > 0 ? 'var(--reports-text)' : 'var(--reports-text-muted)' }};">
                                        {{ $row['phone'] ?: '—' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono tabular-nums"
                                        style="color: {{ $row['digital'] > 0 ? 'var(--reports-text)' : 'var(--reports-text-muted)' }};">
                                        {{ $row['digital'] ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ============================================================
         SECTION 3: OPERATOR SUMMARY
         ============================================================ --}}
    <div class="px-6 py-8" style="border-bottom: 1px solid var(--reports-divider);">
        <div class="text-xs font-bold uppercase tracking-widest mb-5"
             style="color: var(--reports-text-muted); display: flex; align-items: center; gap: 0.5rem;">
            <span style="display: inline-block; width: 3px; height: 1em; background-color: var(--reports-section-bar); border-radius: 2px;"></span>
            Operator Summary
        </div>

        <div class="rounded-lg overflow-hidden"
             style="border: 1px solid var(--reports-border); background-color: var(--reports-surface);">
            @if (count($this->operatorSummary) === 0)
                <div class="flex flex-col items-center justify-center py-12 gap-3">
                    <x-mary-icon name="phosphor-users" class="w-10 h-10 opacity-25" />
                    <p class="text-sm" style="color: var(--reports-text-muted);">No operator data yet.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--reports-border);">
                                <th class="text-left px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">Call Sign</th>
                                <th class="text-left px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">Name</th>
                                <th class="text-right px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">Valid QSOs</th>
                                <th class="text-right px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">Total Logged</th>
                                <th class="text-right px-4 py-3 font-semibold uppercase tracking-wide text-xs"
                                    style="color: var(--reports-text-muted);">Dupes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->operatorSummary as $row)
                                <tr style="border-bottom: 1px solid var(--reports-divider);">
                                    <td class="px-4 py-2.5 font-mono font-semibold"
                                        style="color: var(--reports-text);">
                                        {{ $row['call_sign'] }}
                                    </td>
                                    <td class="px-4 py-2.5"
                                        style="color: var(--reports-text);">
                                        {{ $row['name'] ?: '—' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono font-bold tabular-nums"
                                        style="color: var(--reports-text);">
                                        {{ number_format($row['valid_qsos']) }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono tabular-nums"
                                        style="color: var(--reports-text-muted);">
                                        {{ number_format($row['total_logged']) }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono tabular-nums"
                                        style="color: {{ $row['duplicates'] > 0 ? 'var(--reports-warning)' : 'var(--reports-text-muted)' }};">
                                        {{ $row['duplicates'] ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ============================================================
         SECTION 4: SECTIONS WORKED
         ============================================================ --}}
    <div class="px-6 py-8" style="border-bottom: 1px solid var(--reports-divider);">
        <div class="text-xs font-bold uppercase tracking-widest mb-5"
             style="color: var(--reports-text-muted); display: flex; align-items: center; gap: 0.5rem;">
            <span style="display: inline-block; width: 3px; height: 1em; background-color: var(--reports-section-bar); border-radius: 2px;"></span>
            Sections Worked
            @if (count($this->sectionCounts) > 0)
                <span class="ml-2 text-xs font-normal font-mono"
                      style="color: var(--reports-text-muted);">({{ count($this->sectionCounts) }})</span>
            @endif
        </div>

        @if (count($this->sectionCounts) === 0)
            <div class="flex flex-col items-center justify-center py-12 gap-3 rounded-lg"
                 style="border: 1px solid var(--reports-border); background-color: var(--reports-surface);">
                <x-mary-icon name="phosphor-map-trifold" class="w-10 h-10 opacity-25" />
                <p class="text-sm" style="color: var(--reports-text-muted);">No sections worked yet.</p>
            </div>
        @else
            {{-- Grid of section cards — compact, sorted by count descending --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2">
                @foreach ($this->sectionCounts as $row)
                    <div class="rounded p-2.5 flex flex-col"
                         style="background-color: var(--reports-surface);
                                border: 1px solid var(--reports-border);">
                        <div class="font-mono font-bold text-sm" style="color: var(--reports-text);">
                            {{ $row['code'] }}
                        </div>
                        <div class="text-xs mt-0.5 leading-tight truncate" style="color: var(--reports-text-muted);">
                            {{ $row['name'] }}
                        </div>
                        <div class="font-mono font-bold tabular-nums mt-1 text-sm"
                             style="color: var(--reports-section-bar);">
                            {{ $row['count'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ============================================================
         SECTION 5: VOLUNTEER HOURS
         ============================================================ --}}
    <div class="px-6 py-8" style="border-bottom: 1px solid var(--reports-divider);">
        <div class="text-xs font-bold uppercase tracking-widest mb-5"
             style="color: var(--reports-text-muted); display: flex; align-items: center; gap: 0.5rem;">
            <span style="display: inline-block; width: 3px; height: 1em; background-color: var(--reports-section-bar); border-radius: 2px;"></span>
            Volunteer Hours
            @if (count($this->volunteerHours) > 0)
                <span class="ml-2 text-xs font-normal font-mono"
                      style="color: var(--reports-text-muted);">({{ count($this->volunteerHours) }})</span>
            @endif
        </div>

        <div class="text-xs mb-4" style="color: var(--reports-text-muted);">
            @if ($this->volunteerHoursMode === \App\Support\VolunteerHours::MODE_WALL_CLOCK)
                Wall-clock hours — overlapping shifts merged.
            @else
                Hours counted per role (overlaps not merged).
            @endif
        </div>

        @if (count($this->volunteerHours) === 0)
            <div class="flex flex-col items-center justify-center py-12 gap-3 rounded-lg"
                 style="border: 1px solid var(--reports-border); background-color: var(--reports-surface);">
                <x-mary-icon name="phosphor-clock-countdown" class="w-10 h-10 opacity-25" />
                <p class="text-sm" style="color: var(--reports-text-muted);">No volunteer hours logged yet.</p>
            </div>
        @else
            {{-- Totals summary --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                <div class="rounded-lg p-4 flex flex-col gap-1"
                     style="border: 1px solid var(--reports-border); background-color: var(--reports-surface);">
                    <div class="text-xs font-semibold uppercase tracking-wide"
                         style="color: var(--reports-text-muted);">Total Hours Worked</div>
                    <div class="font-mono font-bold tabular-nums text-2xl"
                         style="color: var(--reports-section-bar);">
                        {{ number_format($this->volunteerHoursTotals['hours_worked'], 1) }}
                    </div>
                </div>
                <div class="rounded-lg p-4 flex flex-col gap-1"
                     style="border: 1px solid var(--reports-border); background-color: var(--reports-surface);">
                    <div class="text-xs font-semibold uppercase tracking-wide"
                         style="color: var(--reports-text-muted);">Total Hours Scheduled</div>
                    <div class="font-mono font-bold tabular-nums text-2xl"
                         style="color: var(--reports-text);">
                        {{ number_format($this->volunteerHoursTotals['hours_signed_up'], 1) }}
                    </div>
                </div>
                <div class="rounded-lg p-4 flex flex-col gap-1"
                     style="border: 1px solid var(--reports-border); background-color: var(--reports-surface);">
                    <div class="text-xs font-semibold uppercase tracking-wide"
                         style="color: var(--reports-text-muted);">Volunteers</div>
                    <div class="font-mono font-bold tabular-nums text-2xl"
                         style="color: var(--reports-text);">
                        {{ $this->volunteerHoursTotals['volunteer_count'] }}
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2">
                @foreach ($this->volunteerHours as $row)
                    <div class="rounded p-2.5 flex flex-col"
                         style="background-color: var(--reports-surface);
                                border: 1px solid var(--reports-border);">
                        <div class="font-semibold text-sm truncate" style="color: var(--reports-text);"
                             title="{{ $row['name'] }}">
                            {{ $row['name'] }}
                        </div>
                        <div class="font-mono font-bold tabular-nums mt-1 text-sm"
                             style="color: var(--reports-section-bar);">
                            {{ number_format($row['hours_worked'], 1) }}
                            <span class="text-xs font-normal" style="color: var(--reports-text-muted);">worked</span>
                        </div>
                        <div class="font-mono tabular-nums text-xs mt-0.5" style="color: var(--reports-text-muted);">
                            {{ number_format($row['hours_signed_up'], 1) }} signed up
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>


    @endif
</div>
