{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="h-full" wire:poll.visible.30s>
    @if ($size === 'tv')
        {{-- TV Mode: every call area visible at once, no interaction; worked
             sections lit, needed sections dim so the room can see what's left. --}}
        <x-card body-class="flex flex-col min-h-0" class="h-full flex flex-col" shadow>
            <div class="flex items-baseline justify-between gap-3 mb-3">
                <h2 class="text-2xl font-bold text-base-content">Sections Worked</h2>
                <span class="text-2xl font-bold tabular-nums text-info whitespace-nowrap">
                    {{ $data['total_worked'] }} <span class="text-lg font-normal text-base-content/70">/ {{ $data['total_sections'] }}</span>
                </span>
            </div>

            @if (empty($data['groups']))
                <div class="flex-1 flex flex-col items-center justify-center text-base-content/70">
                    <x-mary-icon name="phosphor-map-trifold" class="w-16 h-16 mb-3 opacity-50" />
                    <p class="text-xl">No active event</p>
                </div>
            @else
                {{-- One shared grid so the call-area column sizes to its widest label;
                     text scales with the viewport so all areas fit a 720p screen too. --}}
                <div class="flex-1 min-h-0 overflow-hidden grid grid-cols-[auto_1fr] content-between gap-x-3 gap-y-1 text-[clamp(0.625rem,0.75vw,0.875rem)]">
                    @foreach ($data['groups'] as $group)
                        @php
                            $isComplete = $group['total_count'] > 0 && $group['worked_count'] === $group['total_count'];
                        @endphp
                        <span class="text-[1.3em] leading-tight font-bold whitespace-nowrap {{ $isComplete ? 'text-success' : 'text-base-content/70' }}">
                            {{ str_replace(' ', '', $group['label']) }}
                        </span>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($group['sections'] as $section)
                                <span
                                    title="{{ $section['name'] }}"
                                    class="px-[0.4em] py-[0.1em] rounded font-semibold leading-tight
                                        {{ $section['worked'] ? 'bg-success text-success-content' : 'bg-base-200 text-base-content/70' }}"
                                >{{ $section['code'] }}<span class="sr-only">: {{ $section['name'] }}, {{ $section['worked'] ? 'worked' : 'not worked' }}</span></span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>
    @else
    <x-mary-card shadow separator>
        <x-slot:title>
            <div class="flex items-center justify-between gap-2 w-full">
                <span>Sections Worked</span>
                <span class="text-sm font-normal text-base-content/60 whitespace-nowrap">
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
                            :aria-expanded="(openArea === {{ $index }}).toString()"
                            aria-controls="sections-{{ $this->getId() }}-{{ $index }}"
                            class="w-full flex items-center gap-3 px-3 py-2 pointer-coarse:min-h-11 text-left transition-colors
                                {{ $group['worked_count'] === $group['total_count'] && $group['total_count'] > 0
                                    ? 'bg-success/10 hover:bg-success/15'
                                    : 'hover:bg-base-200' }} pointer-coarse:min-w-11"
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
                            <div class="flex-1 min-w-8 h-2 bg-base-300 rounded-full overflow-hidden">
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
                            id="sections-{{ $this->getId() }}-{{ $index }}"
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
                                                    : 'bg-base-200 text-base-content/70 border border-base-300' }}"
                                        >
                                            {{ $section['code'] }}
                                            <span class="sr-only">: {{ $section['name'] }}, {{ $section['worked'] ? 'worked' : 'not worked' }}</span>
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
    @endif
</div>
