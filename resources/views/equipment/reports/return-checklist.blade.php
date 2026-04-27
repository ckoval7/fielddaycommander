@extends('equipment.reports.layout', ['report_title' => 'Equipment Return Checklist'])

@section('content')
    <div class="section">
        <div class="section-title">
            Return Checklist
            <span class="section-note">{{ $summary['total_items'] }} {{ Str::plural('item', $summary['total_items']) }} to return</span>
        </div>

        @if ($return_items->isEmpty())
            <p class="muted">No equipment is currently delivered and pending return.</p>
        @else
            @php
                $rows = collect();
                $return_items
                    ->sortBy(fn ($item) => strtolower($item['owner_name']))
                    ->groupBy('owner_name')
                    ->each(function ($items, $ownerName) use ($rows) {
                        $first = $items->first();
                        $rows->push([
                            '_type' => 'group',
                            'owner_name' => $ownerName,
                            'owner_callsign' => $first['owner_callsign'] ?? null,
                            'count' => $items->count(),
                        ]);
                        foreach ($items as $item) {
                            $rows->push(['_type' => 'item'] + $item);
                        }
                    });
            @endphp

            @include('equipment.reports.partials.paginated-table', [
                'rows' => $rows,
                'columns' => [
                    ['label' => '&#9744;', 'attrs' => 'class="c" style="width: 24px;"'],
                    ['label' => 'Equipment'],
                    ['label' => 'Serial #', 'attrs' => 'style="width: 18%;"'],
                    ['label' => 'Station', 'attrs' => 'style="width: 18%;"'],
                    ['label' => 'Signature', 'attrs' => 'class="c" style="width: 22%;"'],
                ],
                'rowPartial' => 'equipment.reports.partials.return-row',
                'rowHeight' => fn ($r) => ($r['_type'] ?? 'item') === 'group' ? 22 : 30,
                'keepWithNext' => fn ($r) => ($r['_type'] ?? 'item') === 'group',
            ])
        @endif
    </div>
@endsection
