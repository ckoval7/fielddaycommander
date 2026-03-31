<!DOCTYPE html>
<html lang="en">
<head>
    <title>Messages - {{ $event->eventConfiguration->callsign ?? 'Field Day' }}</title>
    <style>
        @media print {
            .page-break { page-break-after: always; }
            body { margin: 0.5in; }
        }
        body { font-family: 'Courier New', monospace; font-size: 12pt; }
        .radiogram, .ics213 { max-width: 7.5in; margin: 0 auto; border: 2px solid #000; padding: 0; }
        .header { text-align: center; font-weight: bold; font-size: 14pt; padding: 8px; border-bottom: 2px solid #000; }
        .preamble { display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 1px solid #000; }
        .preamble-item { padding: 4px 8px; border-right: 1px solid #000; }
        .preamble-item:last-child { border-right: none; }
        .preamble-label { font-size: 8pt; text-transform: uppercase; color: #666; }
        .preamble-value { font-weight: bold; }
        .section { padding: 8px; border-bottom: 1px solid #000; }
        .section-label { font-size: 8pt; text-transform: uppercase; color: #666; margin-bottom: 4px; }
        .text-lines { min-height: 120px; white-space: pre-wrap; }
        .signature-line { border-top: 1px solid #000; padding-top: 4px; }
        .routing { display: grid; grid-template-columns: 1fr 1fr; }
        .routing-item { padding: 4px 8px; }
        .ics-field { display: grid; grid-template-columns: 1fr 1fr; border-bottom: 1px solid #000; }
        .ics-field-item { padding: 4px 8px; border-right: 1px solid #000; }
        .ics-field-item:last-child { border-right: none; }
        .no-print { display: block; text-align: center; margin: 20px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Print</button>
        <button onclick="window.close()">Close</button>
    </div>

    @foreach($messages as $message)
        @if($message->format->value === 'radiogram')
            {{-- ARRL Radiogram Layout --}}
            <div class="radiogram">
                <div class="header">ARRL RADIOGRAM</div>

                <div class="preamble">
                    <div class="preamble-item">
                        <div class="preamble-label">NR</div>
                        <div class="preamble-value">{{ $message->message_number }}</div>
                    </div>
                    <div class="preamble-item">
                        <div class="preamble-label">Precedence</div>
                        <div class="preamble-value">{{ $message->precedence?->abbreviation() ?? '—' }}</div>
                    </div>
                    <div class="preamble-item">
                        <div class="preamble-label">HX</div>
                        <div class="preamble-value">{{ $message->hx_code ? $message->hx_code->label() . ($message->hx_value ? ' ' . $message->hx_value : '') : '—' }}</div>
                    </div>
                    <div class="preamble-item">
                        <div class="preamble-label">Station of Origin</div>
                        <div class="preamble-value">{{ $message->station_of_origin ?? '—' }}</div>
                    </div>
                </div>

                <div class="preamble">
                    <div class="preamble-item">
                        <div class="preamble-label">Check</div>
                        <div class="preamble-value">{{ $message->check ?? '—' }}</div>
                    </div>
                    <div class="preamble-item">
                        <div class="preamble-label">Place of Origin</div>
                        <div class="preamble-value">{{ $message->place_of_origin ?? '—' }}</div>
                    </div>
                    <div class="preamble-item">
                        <div class="preamble-label">Time Filed</div>
                        <div class="preamble-value">{{ $message->filed_at?->format('Hi') ?? '—' }}</div>
                    </div>
                    <div class="preamble-item">
                        <div class="preamble-label">Date</div>
                        <div class="preamble-value">{{ $message->filed_at?->format('M d') ?? '—' }}</div>
                    </div>
                </div>

                <div class="section">
                    <div class="section-label">TO</div>
                    <div>{{ $message->addressee_name }}</div>
                    @if($message->addressee_address)
                        <div>{{ $message->addressee_address }}</div>
                    @endif
                    <div>
                        {{ implode(', ', array_filter([$message->addressee_city, $message->addressee_state])) }}
                        {{ $message->addressee_zip }}
                    </div>
                    @if($message->addressee_phone)
                        <div>{{ $message->addressee_phone }}</div>
                    @endif
                </div>

                <div class="section">
                    <div class="section-label">Message</div>
                    <div class="text-lines">{{ $message->message_text }}</div>
                </div>

                <div class="section">
                    <div class="section-label">Signature</div>
                    <div class="signature-line">{{ $message->signature }}</div>
                </div>

                <div class="section">
                    <div class="routing">
                        <div class="routing-item">
                            <div class="section-label">Sent To</div>
                            <div>{{ $message->sent_to ?? '—' }}</div>
                        </div>
                        <div class="routing-item">
                            <div class="section-label">Received From</div>
                            <div>{{ $message->received_from ?? '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            {{-- ICS-213 Layout --}}
            <div class="ics213">
                <div class="header">ICS-213 GENERAL MESSAGE</div>

                <div class="ics-field">
                    <div class="ics-field-item">
                        <div class="preamble-label">To (Name)</div>
                        <div class="preamble-value">{{ $message->addressee_name }}</div>
                    </div>
                    <div class="ics-field-item">
                        <div class="preamble-label">Position/Title</div>
                        <div class="preamble-value">{{ $message->ics_to_position ?? '—' }}</div>
                    </div>
                </div>

                <div class="ics-field">
                    <div class="ics-field-item">
                        <div class="preamble-label">From (Name)</div>
                        <div class="preamble-value">{{ $message->signature }}</div>
                    </div>
                    <div class="ics-field-item">
                        <div class="preamble-label">Position/Title</div>
                        <div class="preamble-value">{{ $message->ics_from_position ?? '—' }}</div>
                    </div>
                </div>

                <div class="ics-field">
                    <div class="ics-field-item">
                        <div class="preamble-label">Subject</div>
                        <div class="preamble-value">{{ $message->ics_subject ?? '—' }}</div>
                    </div>
                    <div class="ics-field-item">
                        <div class="preamble-label">Date/Time</div>
                        <div class="preamble-value">{{ $message->filed_at?->format('M d, Y Hi') ?? '—' }}</div>
                    </div>
                </div>

                <div class="section">
                    <div class="section-label">Message</div>
                    <div class="text-lines">{{ $message->message_text }}</div>
                </div>

                @if($message->ics_reply_text)
                    <div class="section">
                        <div class="section-label">Reply</div>
                        <div class="text-lines">{{ $message->ics_reply_text }}</div>
                    </div>

                    <div class="ics-field">
                        <div class="ics-field-item">
                            <div class="preamble-label">Reply By (Name)</div>
                            <div class="preamble-value">{{ $message->ics_reply_name ?? '—' }}</div>
                        </div>
                        <div class="ics-field-item">
                            <div class="preamble-label">Position/Title</div>
                            <div class="preamble-value">{{ $message->ics_reply_position ?? '—' }}</div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="preamble-label">Reply Date/Time</div>
                        <div class="preamble-value">{{ $message->ics_reply_date?->format('M d, Y Hi') ?? '—' }}</div>
                    </div>
                @endif
            </div>
        @endif

        @if(!$loop->last)
            <div class="page-break"></div>
        @endif
    @endforeach
</body>
</html>
