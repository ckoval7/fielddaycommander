{{--
InfoCard Widget View

Renders a row-based key/value card. Shape:
- $data: ['title' => string, 'rows' => [['label', 'value', 'highlight'?]], 'last_updated_at']
- $size: 'normal' or 'tv'
--}}

<div class="h-full">
    @if ($size === 'tv')
        {{-- TV Mode: Large display for kiosk/TV dashboards --}}
        <x-card class="h-full flex flex-col p-6 sm:p-8" shadow>
            <div class="flex-1 flex flex-col justify-center space-y-4 sm:space-y-6">
                @foreach ($data['rows'] as $row)
                    <div>
                        <div class="text-sm sm:text-base text-base-content/60 mb-1">
                            {{ $row['label'] }}
                        </div>
                        <div class="text-2xl sm:text-3xl lg:text-4xl font-bold break-words {{ ($row['highlight'] ?? false) ? 'text-primary' : 'text-base-content' }}">
                            {{ $row['value'] }}
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Last updated timestamp --}}
            <div class="text-xs text-base-content/40 text-right mt-auto pt-2 border-t border-base-content/5">Updated {{ formatTimeAgo($data['last_updated_at'] ?? null) }}</div>
        </x-card>
    @else
        {{-- Normal Mode: Compact info display --}}
        <x-card class="h-full flex flex-col" shadow>
            <x-slot name="title">{{ $data['title'] ?? 'Info' }}</x-slot>

            <div class="flex-1 space-y-3">
                @foreach ($data['rows'] as $index => $row)
                    @if ($index > 0)
                        <div class="divider my-2"></div>
                    @endif
                    <div class="flex justify-between items-start min-w-0">
                        <span class="text-sm text-base-content/60 flex-shrink-0">{{ $row['label'] }}:</span>
                        <span class="text-sm truncate ml-2 {{ ($row['highlight'] ?? false) ? 'font-bold text-primary' : 'font-medium text-base-content' }}">
                            {{ $row['value'] }}
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Last updated timestamp --}}
            <div class="text-xs text-base-content/40 text-right mt-auto pt-2 border-t border-base-content/5">Updated {{ formatTimeAgo($data['last_updated_at'] ?? null) }}</div>
        </x-card>
    @endif
</div>
