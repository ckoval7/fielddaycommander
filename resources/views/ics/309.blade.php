{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@php
    $start = $event->start_time ? toLocalTime($event->start_time) : null;
    $end = $event->end_time ? toLocalTime($event->end_time) : null;
    $preparedName = $preparedBy ? trim($preparedBy->first_name.' '.$preparedBy->last_name) : '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ICS-309 Communications Log - {{ $event->name }}</title>
    <style>
        @media print {
            body { margin: 0.5in; }
            .no-print { display: none; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
        }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #000; background: #fff; margin: 12px; }
        .form { max-width: 10in; margin: 0 auto; border: 2px solid #000; }
        .title { text-align: center; font-weight: bold; font-size: 13pt; padding: 6px; border-bottom: 2px solid #000; }
        .row { display: grid; grid-template-columns: repeat(3, 1fr); border-bottom: 1px solid #000; }
        .cell { padding: 4px 6px; border-right: 1px solid #000; min-width: 0; }
        .cell:last-child { border-right: none; }
        .label { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; }
        .value { min-height: 1.2em; overflow-wrap: anywhere; }
        .log-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 3px 5px; vertical-align: top; text-align: left; }
        th { font-size: 7.5pt; text-transform: uppercase; background: #eee; }
        td.time { white-space: nowrap; width: 9%; }
        td.station { width: 18%; overflow-wrap: anywhere; }
        td.message { white-space: pre-wrap; overflow-wrap: anywhere; }
        .empty { text-align: center; padding: 16px; color: #555; }
        .footer { display: grid; grid-template-columns: 2fr 1fr 1fr; border-top: 1px solid #000; }
        .no-print { text-align: center; margin: 12px; }
        .no-print button { font-size: 14pt; min-height: 44px; padding: 0 16px; margin: 0 4px; }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>

    <div class="form">
        <div class="title">COMMUNICATIONS LOG (ICS 309)</div>

        <div class="row">
            <div class="cell">
                <div class="label">1. Incident Name</div>
                <div class="value">{{ $event->name }}</div>
            </div>
            <div class="cell">
                <div class="label">2. Operational Period</div>
                <div class="value">
                    {{ $start?->format('Y-m-d H:i') }} to {{ $end?->format('Y-m-d H:i T') }}
                </div>
            </div>
            <div class="cell">
                <div class="label">3. Radio Net Name or Position/Tactical Call</div>
                <div class="value">
                    @if($net)
                        {{ collect([$net->name, $net->details(), $net->net_control_station])->filter()->implode(' · ') }}
                    @else
                        {{ $config->callsign }}
                    @endif
                </div>
            </div>
        </div>

        <div class="log-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>From (Call Sign / ID)</th>
                        <th>To (Call Sign / ID)</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($entries as $entry)
                        <tr>
                            <td class="time">{{ toLocalTime($entry->logged_at)->format('H:i') }}</td>
                            <td class="station">{{ \App\Models\NetLogEntry::displayStation($entry->from_station, $entry->from_callsign) }}</td>
                            <td class="station">{{ \App\Models\NetLogEntry::displayStation($entry->to_station, $entry->to_callsign) ?: 'All stations' }}</td>
                            <td class="message">@if(! $net && $entry->net)<em>{{ $entry->net->name }}:</em> @endif @if($entry->priority !== \App\Enums\NetPriority::Routine)<strong>[{{ strtoupper($entry->priority->label()) }}]</strong> @endif{{ $entry->message }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="empty">No net log entries for this event.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="footer">
            <div class="cell">
                <div class="label">5. Prepared By (Name, Call Sign)</div>
                <div class="value">{{ collect([$preparedName, $preparedBy?->call_sign])->filter()->implode(', ') }}</div>
            </div>
            <div class="cell">
                <div class="label">Signature</div>
                <div class="value">&nbsp;</div>
            </div>
            <div class="cell">
                <div class="label">6. Date &amp; Time Prepared</div>
                <div class="value">{{ toLocalTime(appNow())->format('Y-m-d H:i T') }}</div>
            </div>
        </div>
    </div>
</body>
</html>
