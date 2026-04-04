<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>{{ $callsign }} — Field Day Club Summary</title>
    <style>
        /* -------------------------------------------------------
           Base — dompdf compatible CSS2 subset
           Font: DejaVu Sans for reliable Unicode rendering
        ------------------------------------------------------- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        @page { margin: 48px 0; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #1e293b;
            background: #ffffff;
            line-height: 1.5;
        }

        /* -------------------------------------------------------
           HEADER BAR
           Deep blue background, white text
        ------------------------------------------------------- */
        .header-bar {
            background-color: #1e3ea8;
            color: #ffffff;
            padding: 18px 24px 14px 24px;
            margin-bottom: 20px;
        }

        .header-callsign {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-family: DejaVu Sans Mono, monospace;
            line-height: 1.1;
        }

        .header-club {
            font-size: 14px;
            margin-top: 4px;
            color: #bfcfef;
        }

        .header-meta {
            font-size: 11px;
            margin-top: 6px;
            color: #bfcfef;
            letter-spacing: 0.04em;
        }

        /* -------------------------------------------------------
           PAGE SECTIONS
        ------------------------------------------------------- */
        .section {
            margin: 0 24px 20px 24px;
        }

        .section-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            border-bottom: 2px solid #1e3ea8;
            padding-bottom: 4px;
            margin-bottom: 12px;
        }

        /* -------------------------------------------------------
           SCORE EQUATION BOX
           Prominent bordered box with the scoring formula
        ------------------------------------------------------- */
        .score-box {
            border: 2px solid #1e3ea8;
            padding: 14px 18px;
            margin-bottom: 6px;
            background-color: #f0f4ff;
        }

        .score-equation {
            text-align: center;
            font-size: 13px;
            color: #1e293b;
            margin-bottom: 10px;
        }

        .score-equation .num {
            font-family: DejaVu Sans Mono, monospace;
            font-weight: bold;
            font-size: 18px;
            color: #1e3ea8;
        }

        .score-equation .op {
            font-size: 16px;
            color: #64748b;
            padding: 0 4px;
        }

        .score-equation .label {
            display: block;
            font-size: 9px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-top: 2px;
        }

        .score-final {
            text-align: center;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #c7d4f0;
        }

        .score-final .final-num {
            font-family: DejaVu Sans Mono, monospace;
            font-weight: bold;
            font-size: 28px;
            color: #1e3ea8;
        }

        .score-final .final-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            margin-top: 2px;
        }

        /* -------------------------------------------------------
           TABLES — clean borders, alternating rows
        ------------------------------------------------------- */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        th {
            background-color: #f1f5f9;
            color: #475569;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-size: 9.5px;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            text-align: left;
        }

        th.num {
            text-align: right;
        }

        td {
            padding: 5px 8px;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            vertical-align: middle;
        }

        td.num {
            text-align: right;
            font-family: DejaVu Sans Mono, monospace;
        }

        td.mono {
            font-family: DejaVu Sans Mono, monospace;
            font-weight: bold;
        }

        tr.alt td {
            background-color: #f8fafc;
        }

        .badge-verified {
            background-color: #16a34a;
            color: #ffffff;
            font-size: 9px;
            padding: 1px 5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .badge-claimed {
            background-color: #b45309;
            color: #ffffff;
            font-size: 9px;
            padding: 1px 5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* -------------------------------------------------------
           FOOTER
        ------------------------------------------------------- */
        .page-footer {
            position: fixed;
            bottom: -40px;
            left: 0;
            right: 0;
            padding: 6px 24px;
            font-size: 9px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            background: #ffffff;
        }

        .page-footer-left {
            float: left;
        }

        .page-footer-right {
            float: right;
        }
    </style>
</head>
<body>

    {{-- ============================================================
         FOOTER (fixed — renders on every page)
    ============================================================ --}}
    <div class="page-footer">
        <span class="page-footer-left">Generated by Field Day Commander &mdash; {{ $generated_at->format('Y-m-d H:i:s') }} UTC</span>
        <span class="page-footer-right">{{ $callsign }} Field Day Summary</span>
    </div>

    {{-- ============================================================
         1. HEADER BAR
    ============================================================ --}}
    <div class="header-bar">
        <div class="header-callsign">{{ $callsign }}</div>
        @if ($club_name)
            <div class="header-club">{{ $club_name }}</div>
        @endif
        <div class="header-meta">
            {{ $operating_class }}
            &nbsp;&middot;&nbsp;
            {{ $section }}@if ($section_name) &nbsp;&mdash;&nbsp;{{ $section_name }}@endif
            &nbsp;&middot;&nbsp;
            {{ $event_start->format('M j, Y') }} &ndash; {{ $event_end->format('M j, Y') }}
        </div>
    </div>

    {{-- ============================================================
         2. SCORE SUMMARY BOX
    ============================================================ --}}
    <div class="section">
        <div class="section-title">Score Summary</div>

        <div class="score-box">
            {{-- Equation row --}}
            <div class="score-equation">
                <table style="width: auto; margin: 0 auto; border-collapse: collapse; border: none;">
                    <thead>
                        <tr>
                            <th style="text-align: center; padding: 0 8px; border: none; background: none; font-size: inherit; text-transform: none; letter-spacing: normal;">QSO Base Pts</th>
                            <th style="border: none; background: none;"><span class="sr-only">Operator</span></th>
                            <th style="text-align: center; padding: 0 8px; border: none; background: none; font-size: inherit; text-transform: none; letter-spacing: normal;">Power Multi.</th>
                            <th style="border: none; background: none;"><span class="sr-only">Operator</span></th>
                            <th style="text-align: center; padding: 0 8px; border: none; background: none; font-size: inherit; text-transform: none; letter-spacing: normal;">Bonus Pts</th>
                            <th style="border: none; background: none;"><span class="sr-only">Operator</span></th>
                            <th style="text-align: center; padding: 0 8px; border: none; background: none; font-size: inherit; text-transform: none; letter-spacing: normal;">Final Score</th>
                        </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td style="text-align: center; padding: 0 8px; border: none;">
                            <span class="num">{{ number_format($qso_base_points) }}</span>
                        </td>
                        <td style="text-align: center; padding: 0 4px; border: none; vertical-align: middle;">
                            <span class="op">&times;</span>
                        </td>
                        <td style="text-align: center; padding: 0 8px; border: none;">
                            <span class="num">{{ $power_multiplier }}&times;</span>
                        </td>
                        <td style="text-align: center; padding: 0 4px; border: none; vertical-align: middle;">
                            <span class="op">+</span>
                        </td>
                        <td style="text-align: center; padding: 0 8px; border: none;">
                            <span class="num">{{ number_format($bonus_score) }}</span>
                        </td>
                        <td style="text-align: center; padding: 0 4px; border: none; vertical-align: middle;">
                            <span class="op">=</span>
                        </td>
                        <td style="text-align: center; padding: 0 8px; border: none;">
                            <span style="font-family: DejaVu Sans Mono, monospace; font-weight: bold; font-size: 22px; color: #1e3ea8;">{{ number_format($final_score) }}</span>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            {{-- Component breakdown row --}}
            <div style="border-top: 1px solid #c7d4f0; margin-top: 10px; padding-top: 8px;">
                <table style="width: auto; margin: 0 auto; border-collapse: collapse; border: none;">
                    <thead>
                        <tr>
                            <th style="padding: 0 12px; border: none; background: none; font-size: 10px; color: #475569; text-align: center; text-transform: none; letter-spacing: normal;">QSO Score</th>
                            <th style="padding: 0 12px; border: none; background: none; font-size: 10px; color: #475569; text-align: center; text-transform: none; letter-spacing: normal;">Bonus Points</th>
                            <th style="padding: 0 12px; border: none; background: none; font-size: 10px; color: #475569; text-align: center; text-transform: none; letter-spacing: normal;">Power Multiplier</th>
                        </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td style="padding: 0 12px; border: none; text-align: center; font-size: 10px; color: #475569;">
                            <strong style="font-family: DejaVu Sans Mono, monospace; color: #1e293b;">{{ number_format($qso_score) }}</strong>
                        </td>
                        <td style="padding: 0 12px; border: none; text-align: center; font-size: 10px; color: #475569;">
                            <strong style="font-family: DejaVu Sans Mono, monospace; color: #1e293b;">{{ number_format($bonus_score) }}</strong>
                        </td>
                        <td style="padding: 0 12px; border: none; text-align: center; font-size: 10px; color: #475569;">
                            <strong style="font-family: DejaVu Sans Mono, monospace; color: #1e293b;">{{ $power_multiplier }}&times;</strong>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================
         3. BAND / MODE BREAKDOWN TABLE
    ============================================================ --}}
    @if (! empty($band_mode_grid))
        <div class="section">
            <div class="section-title">Band / Mode Breakdown</div>
            <table>
                <thead>
                    <tr>
                        <th>Mode</th>
                        @foreach ($bands as $band)
                            <th class="num">{{ $band['name'] ?? $band->name }}</th>
                        @endforeach
                        <th class="num">Total QSOs</th>
                        <th class="num">Points</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($band_mode_grid as $i => $row)
                        <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                            <td class="mono">{{ is_object($row['mode']) ? $row['mode']->name : $row['mode'] }}</td>
                            @foreach ($bands as $band)
                                @php $bandId = is_object($band) ? $band->id : $band['id']; @endphp
                                <td class="num">
                                    {{ ($row['cells'][$bandId] ?? 0) > 0 ? $row['cells'][$bandId] : '—' }}
                                </td>
                            @endforeach
                            <td class="num" style="font-weight: bold;">
                                {{ ($row['total'] ?? $row['total_count'] ?? 0) > 0 ? ($row['total'] ?? $row['total_count']) : '—' }}
                            </td>
                            <td class="num">
                                {{ ($row['total_points'] ?? 0) > 0 ? $row['total_points'] : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ============================================================
         4. BONUSES TABLE
    ============================================================ --}}
    @if (! empty($bonuses))
        <div class="section">
            <div class="section-title">Bonus Points</div>
            <table>
                <thead>
                    <tr>
                        <th>Bonus</th>
                        <th class="num">Points</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bonuses as $i => $bonus)
                        @php
                            $bonusName   = is_object($bonus) ? $bonus->name : ($bonus['name'] ?? '');
                            $bonusPoints = is_object($bonus) ? $bonus->points : ($bonus['points'] ?? 0);
                            $bonusVerified = is_object($bonus) ? $bonus->is_verified : ($bonus['is_verified'] ?? false);
                        @endphp
                        <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                            <td>{{ $bonusName }}</td>
                            <td class="num">+{{ number_format($bonusPoints) }}</td>
                            <td>
                                @if ($bonusVerified)
                                    <span class="badge-verified">Verified</span>
                                @else
                                    <span class="badge-claimed">Claimed</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ============================================================
         5. OPERATOR ROSTER TABLE
    ============================================================ --}}
    @if (! empty($operators))
        <div class="section">
            <div class="section-title">Operator Roster</div>
            <table>
                <thead>
                    <tr>
                        <th>Call Sign</th>
                        <th>Name</th>
                        <th class="num">Valid QSOs</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($operators as $i => $op)
                        @php
                            $opCall  = is_object($op) ? $op->call_sign : ($op['call_sign'] ?? '—');
                            $opName  = is_object($op) ? $op->name : ($op['name'] ?? '');
                            $opQsos  = is_object($op) ? $op->valid_qsos : ($op['valid_qsos'] ?? 0);
                        @endphp
                        <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                            <td class="mono">{{ $opCall }}</td>
                            <td>{{ $opName ?: '—' }}</td>
                            <td class="num">{{ number_format($opQsos) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</body>
</html>
