{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-slot:title>Checklists</x-slot:title>

    <div class="p-6 max-sm:px-0">
        <x-header title="Checklists" :subtitle="$eventConfig?->event?->name" separator>
            @can('manage-shifts')
                @unless(\App\Support\StationMode::isActive())
                    <x-slot:actions class="flex-wrap max-sm:w-full">
                        <x-button label="Manage Checklists" icon="phosphor-gear" link="{{ route('checklists.manage') }}" class="btn-outline max-sm:w-full" />
                    </x-slot:actions>
                @endunless
            @endcan
        </x-header>

        @if(! $eventConfig)
            <x-alert icon="phosphor-info" class="alert-info">
                Select an event to view its checklists.
            </x-alert>
        @elseif($this->checklists->isEmpty())
            <x-alert icon="phosphor-info" class="alert-info">
                There aren’t any checklists for this event yet.
            </x-alert>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($this->checklists as $row)
                    @php
                        $checklist = $row['checklist'];
                        $progress = $row['progress'];
                        $done = $progress['total'] > 0 && $progress['done'] >= $progress['total'];
                    @endphp
                    <a
                        wire:key="checklist-{{ $checklist->id }}"
                        href="{{ route('checklists.show', $checklist) }}"
                        class="card bg-base-100 border border-base-300 shadow-sm p-4 hover:border-primary active:bg-base-200 transition-colors"
                    >
                        <div class="flex items-start gap-3">
                            <x-icon :name="$done ? 'phosphor-check-circle' : 'phosphor-clipboard-text'" class="w-6 h-6 shrink-0 {{ $done ? 'text-success' : 'text-base-content/60' }}" />
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <h2 class="font-semibold">{{ $checklist->name }}</h2>
                                    @if($checklist->earnsBonus())
                                        <x-badge value="Bonus" class="badge-warning badge-sm" />
                                    @endif
                                </div>
                                @if($checklist->description)
                                    <p class="text-sm text-base-content/70 mt-1">{{ $checklist->description }}</p>
                                @endif
                                <p class="text-sm mt-2">
                                    @switch($checklist->scope)
                                        @case(\App\Enums\ChecklistScope::Station)
                                            {{ $progress['done'] }} of {{ $progress['total'] }} {{ Str::plural('station', $progress['total']) }} done
                                            @break
                                        @case(\App\Enums\ChecklistScope::Person)
                                            {{ $progress['done'] }} of {{ $progress['total'] }} {{ Str::plural('person', $progress['total']) }} done
                                            @break
                                        @default
                                            {{ $done ? 'Done' : 'Not done yet' }}
                                    @endswitch
                                    <span class="text-base-content/60">· {{ $checklist->items_count }} {{ Str::plural('item', $checklist->items_count) }}</span>
                                </p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
