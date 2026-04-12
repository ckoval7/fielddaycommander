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
            href="{{ route('logbook.export', request()->query()) }}"
            class="btn btn-sm btn-primary min-h-[2.75rem] sm:min-h-[1.75rem]"
            target="_blank"
        >
            <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
            <span class="ml-1">Export CSV</span>
        </a>
    </div>

    @if($this->contacts->isEmpty())
        <div class="text-center py-12">
            <x-icon name="o-magnifying-glass" class="w-16 h-16 mx-auto text-base-content/30" />
            <p class="mt-4 text-base-content/70">No contacts found matching the current filters.</p>
            <p class="text-sm text-base-content/50 mt-2">Try adjusting your filter criteria.</p>
        </div>
    @else
        {{-- Desktop Table View --}}
        <div class="hidden lg:block overflow-x-auto">
            @php
                $headers = [
                    ['key' => 'qso_time', 'label' => 'QSO Time', 'class' => 'w-40'],
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

            <x-table :headers="$headers" :rows="$this->contacts" class="table-sm">
                @scope('cell_qso_time', $contact)
                    <span class="text-xs">{{ $contact->qso_time ? \Carbon\Carbon::parse($contact->qso_time)->format('Y-m-d H:i') : 'N/A' }}</span>
                @endscope

                @scope('cell_callsign', $contact)
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-semibold {{ $contact->trashed() ? 'line-through text-base-content/40' : '' }}">{{ $contact->callsign }}</span>
                        @if($contact->trashed())
                            <x-badge value="DELETED" class="badge-xs badge-error" />
                        @endif
                        @if($contact->is_duplicate)
                            <x-badge value="DUP" class="badge-xs badge-warning" />
                        @endif
                        @if($contact->is_transcribed)
                            <span class="badge badge-outline badge-xs badge-warning ml-1">transcribed</span>
                        @endif
                    </div>
                @endscope

                @scope('cell_band', $contact)
                    <span class="text-sm">{{ $contact->band?->name ?? 'N/A' }}</span>
                @endscope

                @scope('cell_mode', $contact)
                    <span class="text-sm">{{ $contact->mode?->name ?? 'N/A' }}</span>
                @endscope

                @scope('cell_class', $contact)
                    @php
                        $class = null;
                        if ($contact->received_exchange) {
                            $tokens = preg_split('/\s+/', trim($contact->received_exchange));
                            if (count($tokens) >= 1) {
                                $class = $tokens[0];
                            }
                        }
                    @endphp
                    <span class="text-sm font-mono">{{ $class ?? 'N/A' }}</span>
                @endscope

                @scope('cell_section', $contact)
                    <x-badge :value="$contact->section?->code ?? 'N/A'" class="badge-sm badge-primary" />
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
                                    icon="o-arrow-uturn-left"
                                    wire:click="$dispatch('restore-contact', { contactId: {{ $contact->id }} })"
                                    wire:confirm="Are you sure you want to restore this contact?"
                                    class="btn-ghost btn-xs text-success"
                                    tooltip="Restore"
                                    spinner
                                />
                            @else
                                <x-button
                                    icon="o-pencil-square"
                                    wire:click="$dispatch('open-edit-contact', { contactId: {{ $contact->id }} })"
                                    class="btn-ghost btn-xs"
                                    tooltip="Edit"
                                    spinner
                                />
                                <x-button
                                    icon="o-trash"
                                    wire:click="$dispatch('delete-contact', { contactId: {{ $contact->id }} })"
                                    wire:confirm="Are you sure you want to delete this contact?"
                                    class="btn-ghost btn-xs text-error"
                                    tooltip="Delete"
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
            @foreach($this->contacts as $contact)
                <x-card class="shadow-sm {{ $contact->is_duplicate ? 'border-l-4 border-l-warning bg-warning/5' : '' }} {{ $contact->trashed() ? 'opacity-60' : '' }}">
                    <div class="flex flex-col gap-3">
                        {{-- Callsign and Badges --}}
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <div class="font-mono font-bold text-lg truncate {{ $contact->trashed() ? 'line-through' : '' }}">{{ $contact->callsign }}</div>
                                <div class="text-xs text-base-content/60 mt-1">
                                    {{ $contact->qso_time ? \Carbon\Carbon::parse($contact->qso_time)->format('M d, Y H:i') : 'N/A' }}
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1 flex-shrink-0">
                                @if($contact->is_duplicate)
                                    <x-badge value="DUPLICATE" class="badge-xs badge-warning" />
                                @endif
                                @if($contact->is_transcribed)
                                    <span class="badge badge-outline badge-xs badge-warning">transcribed</span>
                                @endif
                                @if($contact->operatingSession?->station?->is_gota ?? false)
                                    <x-badge value="GOTA" class="badge-xs badge-info" />
                                @endif
                                @if($contact->trashed())
                                    <x-badge value="DELETED" class="badge-xs badge-error" />
                                @endif
                            </div>
                        </div>
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
                        @php
                            $mobileClass = null;
                            if ($contact->received_exchange) {
                                $mobileTokens = preg_split('/\s+/', trim($contact->received_exchange));
                                if (count($mobileTokens) >= 1) {
                                    $mobileClass = $mobileTokens[0];
                                }
                            }
                        @endphp
                        <div class="flex items-center gap-2 text-sm flex-wrap">
                            <div class="flex items-center gap-1">
                                <x-icon name="o-signal" class="w-4 h-4 text-base-content/50" />
                                <span>{{ $contact->band?->name ?? 'N/A' }}</span>
                            </div>
                            <span class="text-base-content/30">•</span>
                            <div class="flex items-center gap-1">
                                <x-icon name="o-radio" class="w-4 h-4 text-base-content/50" />
                                <span>{{ $contact->mode?->name ?? 'N/A' }}</span>
                            </div>
                            <span class="text-base-content/30">•</span>
                            <span class="font-mono">{{ $mobileClass ?? 'N/A' }}</span>
                            <x-badge :value="$contact->section?->code ?? 'N/A'" class="badge-sm badge-primary" />
                        </div>

                        {{-- Points, Station, Logger --}}
                        <div class="flex items-center justify-between pt-2 border-t border-base-300 text-sm">
                            <div class="flex items-center gap-4">
                                <div>
                                    <span class="text-xs text-base-content/60">Points:</span>
                                    <span class="ml-1 font-semibold {{ $contact->is_duplicate ? 'text-warning line-through' : 'text-success' }}">
                                        {{ $contact->points }}
                                    </span>
                                </div>
                                <div class="truncate max-w-[120px]">
                                    <span class="text-xs text-base-content/60">Station:</span>
                                    <span class="ml-1">{{ $contact->operatingSession?->station?->name ?? 'N/A' }}</span>
                                </div>
                            </div>
                            <div class="text-xs text-base-content/60 truncate max-w-[100px]">
                                {{ $contact->logger ? $contact->logger->first_name : 'N/A' }}
                            </div>
                        </div>

                        {{-- Actions (edit-contacts only) --}}
                        @can('edit-contacts')
                            <div class="flex items-center gap-2 pt-2 border-t border-base-300">
                                @if($contact->trashed())
                                    <x-button
                                        icon="o-arrow-uturn-left"
                                        label="Restore"
                                        wire:click="$dispatch('restore-contact', { contactId: {{ $contact->id }} })"
                                        wire:confirm="Are you sure you want to restore this contact?"
                                        class="btn-ghost btn-xs text-success"
                                        spinner
                                    />
                                @else
                                    <x-button
                                        icon="o-pencil-square"
                                        label="Edit"
                                        wire:click="$dispatch('open-edit-contact', { contactId: {{ $contact->id }} })"
                                        class="btn-ghost btn-xs"
                                        spinner
                                    />
                                    <x-button
                                        icon="o-trash"
                                        label="Delete"
                                        wire:click="$dispatch('delete-contact', { contactId: {{ $contact->id }} })"
                                        wire:confirm="Are you sure you want to delete this contact?"
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
