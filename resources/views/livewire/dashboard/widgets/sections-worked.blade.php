<div>
    <x-mary-card shadow separator>
        <x-slot:title>
            <div class="flex items-center justify-between w-full">
                <span>Sections Worked</span>
                <span class="text-sm font-normal text-base-content/60">
                    {{ $data['total_worked'] }} / {{ $data['total_sections'] }}
                </span>
            </div>
        </x-slot:title>
        <x-slot:menu>
            <x-mary-icon name="phosphor-map-trifold" class="w-5 h-5 text-info" />
        </x-slot:menu>

        @if (empty($data['groups']))
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <x-mary-icon name="phosphor-map-trifold" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                <p>No active event</p>
            </div>
        @else
            <div x-data="{ openArea: null }" class="space-y-1">
                @foreach ($data['groups'] as $index => $group)
                    <div class="border border-base-300 rounded-lg overflow-hidden">
                        {{-- Accordion Header --}}
                        <button
                            type="button"
                            @click="openArea = openArea === {{ $index }} ? null : {{ $index }}"
                            class="w-full flex items-center gap-3 px-3 py-2 text-left transition-colors
                                {{ $group['worked_count'] === $group['total_count'] && $group['total_count'] > 0
                                    ? 'bg-success/10 hover:bg-success/15'
                                    : 'hover:bg-base-200' }}"
                        >
                            {{-- Chevron --}}
                            <x-mary-icon
                                name="phosphor-caret-right"
                                class="w-4 h-4 flex-shrink-0 transition-transform duration-200"
                                ::class="openArea === {{ $index }} ? 'rotate-90' : ''"
                            />

                            {{-- Call Area Label --}}
                            <span class="font-bold text-sm whitespace-nowrap">
                                {{ $group['label'] }}
                            </span>

                            {{-- Progress Bar --}}
                            <div class="flex-1 h-2 bg-base-300 rounded-full overflow-hidden">
                                @php
                                    $pct = $group['total_count'] > 0
                                        ? round(($group['worked_count'] / $group['total_count']) * 100)
                                        : 0;
                                @endphp
                                <div
                                    class="h-full rounded-full transition-all duration-500
                                        {{ $pct === 100 ? 'bg-success' : 'bg-primary' }}"
                                    style="width: {{ $pct }}%"
                                ></div>
                            </div>

                            {{-- Fraction --}}
                            <span class="text-xs font-medium tabular-nums text-base-content/70 min-w-[3rem] text-right">
                                {{ $group['worked_count'] }}/{{ $group['total_count'] }}
                            </span>
                        </button>

                        {{-- Accordion Body --}}
                        <div
                            x-show="openArea === {{ $index }}"
                            x-collapse
                        >
                            <div class="px-3 py-2 border-t border-base-300 bg-base-100">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($group['sections'] as $section)
                                        <span
                                            title="{{ $section['name'] }}"
                                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium cursor-default transition-colors
                                                {{ $section['worked']
                                                    ? 'bg-success text-success-content'
                                                    : 'bg-base-200 text-base-content/40 border border-base-300' }}"
                                        >
                                            {{ $section['code'] }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-mary-card>
</div>
