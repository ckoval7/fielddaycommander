{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- Results View Component --}}
<x-card class="shadow-md">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <h3 class="text-lg font-semibold">
            Contacts
            @if(!$this->contacts->isEmpty())
                <span class="text-sm font-normal text-base-content/60 ml-2">({{ $this->contacts->count() }} shown)</span>
            @endif
        </h3>
        <a
            href="{{ $this->exportUrl }}"
            class="btn btn-sm btn-primary min-h-[2.75rem] sm:min-h-[1.75rem]"
            target="_blank"
        >
            <x-icon name="phosphor-download-simple" class="w-4 h-4" />
            <span class="ml-1">Export CSV</span>
        </a>
    </div>

    {{-- Bulk Action Bar --}}
    @can('edit-contacts')
        @if(count($selectedIds) > 0)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-4 p-3 bg-primary/10 rounded-lg border border-primary/20">
                <span class="text-sm font-semibold whitespace-nowrap">{{ count($selectedIds) }} selected</span>
                <x-button
                    label="Clear Selection"
                    icon="phosphor-x"
                    class="btn-ghost btn-sm ml-auto sm:order-last"
                    wire:click="deselectAll"
                />
                <div class="flex gap-2 w-full sm:w-auto">
                    <x-button
                        label="Change Logger"
                        icon="phosphor-user"
                        class="btn-primary btn-sm flex-1 sm:flex-none"
                        wire:click="$dispatch('open-bulk-change-logger', { contactIds: $wire.selectedIds })"
                    />
                    <x-button
                        label="Delete"
                        icon="phosphor-trash"
                        class="btn-error btn-sm flex-1 sm:flex-none"
                        wire:click="$dispatch('bulk-delete-contacts', { contactIds: $wire.selectedIds })"
                        wire:confirm="Delete {{ count($selectedIds) }} {{ str('contact')->plural(count($selectedIds)) }}? You can restore them from the logbook."
                    />
                </div>
            </div>
        @endif
    @endcan

    @if($this->contacts->isEmpty())
        <div class="text-center py-12">
            <x-icon name="phosphor-magnifying-glass" class="w-16 h-16 mx-auto text-base-content/30" />
            <p class="mt-4 text-base-content/70">No contacts found.</p>
            <p class="text-sm text-base-content/50 mt-2">Clear any active filters to check the full logbook.</p>
        </div>
    @else
        {{-- Desktop Table View --}}
        <div
            class="hidden lg:block overflow-x-auto"
            x-data
            x-effect="
                if ($wire.selectedIds.length === 0) {
                    $el.querySelectorAll('input[id^=checkAll-]').forEach(cb => cb.checked = false);
                }
            "
        >
            @php
                $headers = [
                    ['key' => 'qso_time', 'label' => 'QSO Time (UTC)', 'class' => 'w-40'],
                    ['key' => 'callsign', 'label' => 'Callsign', 'class' => 'w-32'],
                    ['key' => 'band', 'label' => 'Band', 'class' => 'w-24'],
                    ['key' => 'mode', 'label' => 'Mode', 'class' => 'w-24'],
                    ['key' => 'class', 'label' => 'Class', 'class' => 'w-20'],
                    ['key' => 'section', 'label' => 'Section', 'class' => 'w-24'],
                    ['key' => 'points', 'label' => 'Points', 'class' => 'w-20'],
                    ['key' => 'station', 'label' => 'Station', 'class' => 'w-32'],
                    ['key' => 'logger', 'label' => 'Logger', 'class' => 'w-32'],
                ];

                if (auth()->check() && auth()->user()->can('edit-contacts')) {
                    $headers[] = ['key' => 'actions', 'label' => 'Actions', 'class' => 'w-24'];
                }
            @endphp

            @php
                $canEdit = auth()->check() && auth()->user()->can('edit-contacts');
                $noCreditReasons = $this->noCreditReasons;
                $attentionReasons = $this->attentionReasons;
            @endphp
            <x-table :headers="$headers" :rows="$this->contacts" class="table-sm" :selectable="$canEdit" wire:model.live="selectedIds">
                @scope('cell_qso_time', $contact)
                    <span class="text-xs">{{ $contact->qso_time ? \Carbon\Carbon::parse($contact->qso_time)->format('Y-m-d H:i') . ' UTC' : 'N/A' }}</span>
                @endscope

                @scope('cell_callsign', $contact, $noCreditReasons, $attentionReasons)
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-semibold text-lg {{ $contact->trashed() ? 'line-through text-base-content/40' : '' }}">{{ $contact->callsign }}</span>
                        @if($contact->trashed())
                            <x-badge value="DELETED" class="badge-xs badge-error" />
                        @endif
                        @if($contact->is_duplicate)
                            <x-badge value="Dupe" class="badge-xs badge-warning" />
                        @endif
                        @if($contact->is_transcribed)
                            <span class="badge badge-outline badge-xs badge-warning ml-1">transcribed</span>
                        @endif
                        @isset($noCreditReasons[$contact->id])
                            <span class="badge badge-outline badge-xs badge-error whitespace-nowrap cursor-help" title="{{ $noCreditReasons[$contact->id] }}">no credit</span>
                        @endisset
                        @foreach($attentionReasons[$contact->id] ?? [] as $reason)
                            <x-badge :value="$reason" class="badge-xs badge-warning whitespace-nowrap" />
                        @endforeach
                    </div>
                @endscope

                @scope('cell_band', $contact)
                    <span class="text-sm">{{ $contact->band?->name ?? 'N/A' }}</span>
                @endscope

                @scope('cell_mode', $contact)
                    <span class="text-sm">{{ $contact->mode?->name ?? 'N/A' }}@if($contact->submode)<span class="text-base-content/60"> &middot; {{ $contact->submode }}</span>@endif</span>
                @endscope

                @scope('cell_class', $contact)
                    <span class="text-sm">{{ $contact->exchange_class ?? 'N/A' }}</span>
                @endscope

                @scope('cell_section', $contact)
                    <span class="text-sm">{{ $contact->section?->code ?? 'N/A' }}</span>
                @endscope

                @scope('cell_points', $contact)
                    <span class="text-sm font-semibold {{ $contact->is_duplicate ? 'text-warning line-through' : 'text-success' }}">
                        {{ $contact->points }}
                    </span>
                @endscope

                @scope('cell_station', $contact)
                    <div class="flex items-center gap-1">
                        <span class="text-sm truncate">{{ $contact->operatingSession?->station?->name ?? 'N/A' }}</span>
                        @if($contact->operatingSession?->station?->is_gota ?? false)
                            <x-badge value="GOTA" class="badge-xs badge-info" />
                        @endif
                    </div>
                    @if($contact->is_gota_contact && ($contact->gota_operator_first_name || $contact->gotaOperator))
                        <div class="text-xs text-base-content/60 mt-0.5">
                            @if($contact->gotaOperator)
                                Op: {{ $contact->gotaOperator->first_name }} {{ $contact->gotaOperator->last_name }}
                                ({{ $contact->gotaOperator->call_sign }})
                            @else
                                Op: {{ $contact->gota_operator_first_name }} {{ $contact->gota_operator_last_name }}
                                @if($contact->gota_operator_callsign)
                                    ({{ $contact->gota_operator_callsign }})
                                @endif
                            @endif
                        </div>
                    @endif
                @endscope

                @scope('cell_logger', $contact)
                    <span class="text-sm truncate">{{ $contact->logger ? $contact->logger->first_name . ' ' . $contact->logger->last_name : 'N/A' }}</span>
                @endscope

                @scope('cell_actions', $contact)
                    @can('edit-contacts')
                        <div class="flex items-center gap-1">
                            @if($contact->trashed())
                                <x-button
                                    icon="phosphor-arrow-u-up-left"
                                    wire:click="$dispatch('restore-contact', { contactId: {{ $contact->id }} })"
                                    wire:confirm="Restore this contact?"
                                    class="btn-ghost btn-xs text-success"
                                    tooltip="Restore contact"
                                    spinner
                                />
                            @else
                                <x-button
                                    icon="phosphor-note-pencil"
                                    wire:click="$dispatch('open-edit-contact', { contactId: {{ $contact->id }} })"
                                    class="btn-ghost btn-xs"
                                    tooltip="Edit contact"
                                    spinner
                                />
                                <x-button
                                    icon="phosphor-trash"
                                    wire:click="$dispatch('delete-contact', { contactId: {{ $contact->id }} })"
                                    wire:confirm="Delete this contact? You can restore it from the logbook."
                                    class="btn-ghost btn-xs text-error"
                                    tooltip="Delete contact"
                                    spinner
                                />
                            @endif
                        </div>
                    @endcan
                @endscope
            </x-table>
        </div>

        {{-- Mobile Card View --}}
        <div class="lg:hidden grid grid-cols-1 gap-4">
            @php
                $noCreditReasons = $this->noCreditReasons;
                $attentionReasons = $this->attentionReasons;
            @endphp
            @foreach($this->contacts as $contact)
                <x-card class="shadow-sm {{ $contact->is_duplicate ? 'border-l-4 border-l-warning bg-warning/5' : '' }} {{ $contact->trashed() ? 'opacity-60' : '' }}">
                    <div class="flex flex-col gap-3">
                        {{-- Selection and Callsign --}}
                        <div class="flex items-start justify-between gap-2">
                            @can('edit-contacts')
                                <label class="flex items-center justify-center min-h-11 min-w-11 -m-2 flex-shrink-0 cursor-pointer">
                                    <span class="sr-only">Select {{ $contact->callsign }}</span>
                                    <input type="checkbox" class="checkbox checkbox-sm" value="{{ $contact->id }}" wire:model.live="selectedIds" />
                                </label>
                            @endcan
                            <div class="flex-1 min-w-0">
                                <div class="font-mono font-bold text-xl truncate {{ $contact->trashed() ? 'line-through' : '' }}">{{ $contact->callsign }}</div>
                                <div class="text-xs text-base-content/60 mt-1">
                                    {{ $contact->qso_time ? \Carbon\Carbon::parse($contact->qso_time)->format('M d, Y H:i') . ' UTC' : 'N/A' }}
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1 flex-shrink-0">
                                @if($contact->is_duplicate)
                                    <x-badge value="Dupe" class="badge-xs badge-warning" />
                                @endif
                                @if($contact->is_transcribed)
                                    <span class="badge badge-outline badge-xs badge-warning">transcribed</span>
                                @endif
                                @isset($noCreditReasons[$contact->id])
                                    <span class="badge badge-outline badge-xs badge-error whitespace-nowrap cursor-help" title="{{ $noCreditReasons[$contact->id] }}">no credit</span>
                                @endisset
                                @foreach($attentionReasons[$contact->id] ?? [] as $reason)
                                    <x-badge :value="$reason" class="badge-xs badge-warning whitespace-nowrap" />
                                @endforeach
                                @if($contact->operatingSession?->station?->is_gota ?? false)
                                    <x-badge value="GOTA" class="badge-xs badge-info" />
                                @endif
                                @if($contact->trashed())
                                    <x-badge value="DELETED" class="badge-xs badge-error" />
                                @endif
                            </div>
                        </div>
                        @isset($noCreditReasons[$contact->id])
                            <div class="text-xs text-error">{{ $noCreditReasons[$contact->id] }}</div>
                        @endisset
                        @if($contact->is_gota_contact && ($contact->gota_operator_first_name || $contact->gotaOperator))
                            <div class="text-xs text-base-content/60">
                                GOTA Op:
                                @if($contact->gotaOperator)
                                    {{ $contact->gotaOperator->first_name }} {{ $contact->gotaOperator->last_name }}
                                @else
                                    {{ $contact->gota_operator_first_name }} {{ $contact->gota_operator_last_name }}
                                @endif
                            </div>
                        @endif

                        {{-- Band, Mode, Class, Section --}}
                        <div class="flex items-center gap-2 text-sm flex-wrap">
                            <div class="flex items-center gap-1">
                                <x-icon name="phosphor-cell-signal-high" class="w-4 h-4 text-base-content/50" />
                                <span>{{ $contact->band?->name ?? 'N/A' }}</span>
                            </div>
                            <span class="text-base-content/30">•</span>
                            <div class="flex items-center gap-1">
                                <x-icon name="phosphor-radio" class="w-4 h-4 text-base-content/50" />
                                <span>{{ $contact->mode?->name ?? 'N/A' }}@if($contact->submode) &middot; {{ $contact->submode }}@endif</span>
                            </div>
                            <span class="text-base-content/30">•</span>
                            <span>{{ $contact->exchange_class ?? 'N/A' }}</span>
                            <span>{{ $contact->section?->code ?? 'N/A' }}</span>
                        </div>

                        {{-- Points, Station, Logger --}}
                        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 pt-2 border-t border-base-300 text-sm">
                            <div>
                                <span class="text-xs text-base-content/60">Points:</span>
                                <span class="ml-1 font-semibold {{ $contact->is_duplicate ? 'text-warning line-through' : 'text-success' }}">
                                    {{ $contact->points }}
                                </span>
                            </div>
                            <div class="min-w-0 flex-1 break-words">
                                <span class="text-xs text-base-content/60">Station:</span>
                                <span class="ml-1">{{ $contact->operatingSession?->station?->name ?? 'N/A' }}</span>
                            </div>
                            <div class="text-xs text-base-content/60 break-words">
                                {{ $contact->logger ? $contact->logger->first_name : 'N/A' }}
                            </div>
                        </div>

                        {{-- Actions (edit-contacts only) --}}
                        @can('edit-contacts')
                            <div class="flex items-center gap-2 pt-2 border-t border-base-300">
                                @if($contact->trashed())
                                    <x-button
                                        icon="phosphor-arrow-u-up-left"
                                        label="Restore"
                                        wire:click="$dispatch('restore-contact', { contactId: {{ $contact->id }} })"
                                        wire:confirm="Restore this contact?"
                                        class="btn-ghost btn-xs text-success"
                                        spinner
                                    />
                                @else
                                    <x-button
                                        icon="phosphor-note-pencil"
                                        label="Edit"
                                        wire:click="$dispatch('open-edit-contact', { contactId: {{ $contact->id }} })"
                                        class="btn-ghost btn-xs"
                                        spinner
                                    />
                                    <x-button
                                        icon="phosphor-trash"
                                        label="Delete"
                                        wire:click="$dispatch('delete-contact', { contactId: {{ $contact->id }} })"
                                        wire:confirm="Delete this contact? You can restore it from the logbook."
                                        class="btn-ghost btn-xs text-error"
                                        spinner
                                    />
                                @endif
                            </div>
                        @endcan
                    </div>
                </x-card>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-6">
            {{ $this->contacts->links() }}
        </div>
    @endif
</x-card>
