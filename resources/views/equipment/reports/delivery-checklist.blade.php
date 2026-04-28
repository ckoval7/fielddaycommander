@extends('equipment.reports.layout', ['report_title' => 'Equipment Delivery Checklist'])

@section('content')
    <div class="section">
        <div class="section-title">
            Delivery Checklist
            <span class="section-note">{{ $checklist_items->count() }} items expected</span>
        </div>

        @if ($checklist_items->isEmpty())
            <p class="muted">No equipment scheduled for delivery.</p>
        @else
            @php
                // Group items by owner so each owner's deliveries stay together,
                // with an inline header row before each group.
                $rows = collect();
                $checklist_items
                    ->sortBy(fn ($item) => strtolower($item['owner_name']))
                    ->groupBy('owner_name')
                    ->each(function ($items, $ownerName) use ($rows) {
                        $first = $items->first();
                        $rows->push([
                            '_type' => 'group',
                            'owner_name' => $ownerName,
                            'owner_callsign' => $first['owner_callsign'] ?? null,
                            'owner_phone' => $first['owner_phone'] ?? null,
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
                    ['label' => 'Expected', 'attrs' => 'style="width: 18%;"'],
                    ['label' => 'Equipment'],
                    ['label' => 'Contact', 'attrs' => 'style="width: 22%;"'],
                    ['label' => 'Signature', 'attrs' => 'class="c" style="width: 22%;"'],
                ],
                'rowPartial' => 'equipment.reports.partials.delivery-row',
                'rowHeight' => fn ($r) => ($r['_type'] ?? 'item') === 'group' ? 22 : 30,
                'keepWithNext' => fn ($r) => ($r['_type'] ?? 'item') === 'group',
            ])
        @endif
    </div>
@endsection
