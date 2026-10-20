{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@extends('equipment.reports.layout', ['report_title' => 'Equipment Owner Contact List'])

@section('content')
    <div class="section">
        <div class="section-title">
            Owner Contacts
            <span class="section-note">{{ $contacts->count() }} {{ Str::plural('owner', $contacts->count()) }}</span>
        </div>

        @if ($contacts->isEmpty())
            <p class="muted">No equipment owners on record for this event.</p>
        @else
            @php
                // Fixed column widths so wrapping can be predicted. The table is 540pt
                // wide; each cell loses 12px of padding and 1px of border (9.75pt).
                $wrap = \App\Support\PdfTextWrap::forDompdf();
                $lineHeight = $wrap->lineHeight();
                $innerWidth = fn (float $share) => $share * 540 - 9.75;
            @endphp
            @include('equipment.reports.partials.paginated-table', [
                'rows' => $contacts,
                'columns' => [
                    ['label' => 'Owner', 'attrs' => 'style="width: 22%;"'],
                    ['label' => 'Callsign', 'attrs' => 'style="width: 12%;"'],
                    ['label' => 'Email', 'attrs' => 'style="width: 28%;"'],
                    ['label' => 'Emergency Contacts', 'attrs' => 'style="width: 27%;"'],
                    ['label' => 'Equipment', 'attrs' => 'class="r" style="width: 11%;"'],
                ],
                'rowPartial' => 'equipment.reports.partials.owner-contact-row',
                // A row is as tall as its most-wrapped cell. Emergency contacts list every
                // phone on the owner's gear, so a row can be one line or four.
                'rowHeight' => function ($r) use ($wrap, $lineHeight, $innerWidth) {
                    $lines = max(
                        $wrap->lineCount($r['owner_name'], $innerWidth(0.22)),
                        $wrap->lineCount($r['email'], $innerWidth(0.28)),
                        $wrap->lineCount($r['emergency_contacts']->implode(', '), $innerWidth(0.27)),
                    );

                    // Plus 6px of padding and 1px of border.
                    return $lines * $lineHeight + 5.25;
                },
                // Measured on the rendered PDF: page 1's rows start about 116pt down
                // and later pages' at about 63pt, and the footer margin begins at 720pt.
                // Leave a few points spare for a club name or event name that wraps.
                'firstChunkBudget' => 590,
                'chunkBudget' => 650,
            ])
        @endif
    </div>
@endsection
