<!DOCTYPE html>
<html>
<head>
    <title>Radiogram{{ $messages->count() > 1 ? 's' : '' }} - {{ $event->eventConfiguration->callsign ?? 'Field Day' }}</title>
    <style>
        @media print {
            .page-break { page-break-after: always; }
            body { margin: 0.5in; }
        }
        body { font-family: 'Courier New', monospace; font-size: 12pt; }
        .radiogram { max-width: 7.5in; margin: 0 auto; border: 2px solid #000; padding: 0; }
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
    <div class="radiogram">
        <div class="header">ARRL RADIOGRAM</div>

        {{-- Preamble Row 1 --}}
        <div class="preamble">
            <div class="preamble-item">
                <div class="preamble-label">NR</div>
                <div class="preamble-value">{{ $message->message_number }}</div>
            </div>
            <div class="preamble-item">
                <div class="preamble-label">Precedence</div>
                <div class="preamble-value">{{ $message->precedence->abbreviation() }}</div>
            </div>
            <div class="preamble-item">
                <div class="preamble-label">HX</div>
                <div class="preamble-value">{{ $message->hx_code?->label() ?? '—' }}</div>
            </div>
            <div class="preamble-item">
                <div class="preamble-label">Station of Origin</div>
                <div class="preamble-value">{{ $message->station_of_origin }}</div>
            </div>
        </div>

        {{-- Preamble Row 2 --}}
        <div class="preamble">
            <div class="preamble-item">
                <div class="preamble-label">Check</div>
                <div class="preamble-value">{{ $message->check }}</div>
            </div>
            <div class="preamble-item">
                <div class="preamble-label">Place of Origin</div>
                <div class="preamble-value">{{ $message->place_of_origin }}</div>
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

        {{-- TO Section --}}
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

        {{-- Message Text --}}
        <div class="section">
            <div class="section-label">Message</div>
            <div class="text-lines">{{ $message->message_text }}</div>
        </div>

        {{-- Signature --}}
        <div class="section">
            <div class="section-label">Signature</div>
            <div class="signature-line">{{ $message->signature }}</div>
        </div>

        {{-- Sent/Received --}}
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

    @if(!$loop->last)
        <div class="page-break"></div>
    @endif
    @endforeach
</body>
</html>
