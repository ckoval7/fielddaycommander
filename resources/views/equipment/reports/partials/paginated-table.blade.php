{{--
    Renders a long table as page-sized chunks so DomPDF never has to break a
    table across pages. Each chunk is its own <table> with its own <thead>;
    chunks are separated with a forced page-break.

    Required:
      - $rows         iterable<mixed>            row data
      - $columns      array<int, array{label: string, attrs?: string}>
      - $rowPartial   string                     view name; receives $row, $alt, $index

    Optional:
      - $rowHeight    callable(mixed): float     approx rendered height in pt
                                                 (default 20pt — single-line row)
      - $keepWithNext callable(mixed): bool      true if the row must not be the
                                                 last row in a chunk (e.g. group
                                                 headers — their items have to
                                                 follow on the same page)
      - $firstChunkBudget float                  pt of vertical space available
                                                 to the first chunk (smaller because
                                                 of the header bar / section title
                                                 that sits above it on page 1)
      - $chunkBudget  float                      pt of space available to subsequent
                                                 chunks (full content area minus
                                                 just the table thead)

    Page geometry baseline (letter portrait, layout's 60/72 page margins):
      Content area:               792 - 60 - 72  = 660 pt
      Header bar + meta:          ~75 pt
      Section title + spacing:    ~28 pt
      Thead row:                  ~22 pt
      Chunk top spacer (chunks 2+): 24 pt
      → first chunk default:      660 - 75 - 28 - 22      = 535 pt
      → subsequent chunk default: 660 - 24 - 22           = 614 pt

    Row height conventions (used by callers):
      - 1-line row:   ~20 pt
      - row + 1 sub-line (8px font): ~30 pt
--}}
@php
    $rows = collect($rows)->values();
    $rowHeight = $rowHeight ?? fn ($r) => 20;
    $keepWithNext = $keepWithNext ?? fn ($r) => false;
    $firstChunkBudget = $firstChunkBudget ?? 535;
    $chunkBudget = $chunkBudget ?? 614;

    $chunks = [];
    $current = [];
    $used = 0.0;
    $budget = $firstChunkBudget;

    foreach ($rows as $row) {
        $h = max(1.0, (float) $rowHeight($row));

        if ($used + $h > $budget && $current !== []) {
            // Anti-orphan: if the tail of the current chunk is one or more
            // "keep with next" rows (e.g. a group header), move them into
            // the next chunk so they stay with their first child row.
            $orphanedTail = [];
            $orphanedTailHeight = 0.0;
            while ($current !== [] && $keepWithNext(end($current))) {
                $popped = array_pop($current);
                $orphanedTailHeight += max(1.0, (float) $rowHeight($popped));
                array_unshift($orphanedTail, $popped);
            }

            if ($current !== []) {
                $chunks[] = $current;
            } else {
                // The whole chunk would be orphan headers — keep them where
                // they are rather than producing an empty chunk.
                $current = $orphanedTail;
                $orphanedTail = [];
                $orphanedTailHeight = 0.0;
                $chunks[] = $current;
            }

            $current = $orphanedTail;
            $used = $orphanedTailHeight;
            $budget = $chunkBudget;
        }

        $current[] = $row;
        $used += $h;
    }
    if ($current !== []) {
        $chunks[] = $current;
    }
@endphp

@foreach ($chunks as $chunkIndex => $chunk)
    <div @if ($chunkIndex > 0) style="page-break-before: always; padding-top: 24pt;" @endif>
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $col)
                        <th {!! $col['attrs'] ?? '' !!}>{!! $col['label'] !!}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($chunk as $i => $row)
                    @include($rowPartial, ['row' => $row, 'alt' => $i % 2 === 1, 'index' => $i])
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach
