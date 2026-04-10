{{-- Commitment Details Modal --}}
@if($detailCommitment)
    <x-modal wire:model="showDetailsModal" title="Commitment Details" class="backdrop-blur" box-class="max-w-2xl">
        <div class="space-y-6">
            {{-- Equipment Info --}}
            <div>
                <h3 class="text-sm font-semibold text-base-content/60 uppercase tracking-wider mb-3">Equipment</h3>
                <div class="flex items-start gap-4">
                    @if($detailCommitment->equipment->photo_path)
                        <img
                            src="{{ asset('storage/' . $detailCommitment->equipment->photo_path) }}"
                            alt="Equipment"
                            class="w-20 h-20 object-cover rounded cursor-pointer hover:opacity-80 transition-opacity"
                            wire:click="viewPhoto('{{ $detailCommitment->equipment->photo_path }}', '{{ $detailCommitment->equipment->make }} {{ $detailCommitment->equipment->model }}')"
                        />
                    @else
                        <div class="w-20 h-20 bg-base-300 rounded flex items-center justify-center flex-shrink-0">
                            <x-icon name="o-wrench-screwdriver" class="w-8 h-8 text-base-content/50" />
                        </div>
                    @endif
                    <div class="space-y-1">
                        <div class="font-bold text-lg">{{ $detailCommitment->equipment->make }} {{ $detailCommitment->equipment->model }}</div>
                        <div class="text-sm text-base-content/60">{{ ucfirst(str_replace('_', ' ', $detailCommitment->equipment->type)) }}</div>
                        @if($detailCommitment->equipment->serial_number)
                            <div class="text-sm"><span class="text-base-content/60">Serial:</span> {{ $detailCommitment->equipment->serial_number }}</div>
                        @endif
                        @if($detailCommitment->equipment->power_output_watts)
                            <div class="text-sm"><span class="text-base-content/60">Power:</span> {{ $detailCommitment->equipment->power_output_watts }}W</div>
                        @endif
                        @if($detailCommitment->equipment->value_usd)
                            <div class="text-sm"><span class="text-base-content/60">Value:</span> ${{ number_format($detailCommitment->equipment->value_usd, 2) }}</div>
                        @endif
                        @if($detailCommitment->equipment->bands && count($detailCommitment->equipment->bands) > 0)
                            <div class="flex flex-wrap gap-1 mt-1">
                                @foreach($detailCommitment->equipment->bands as $band)
                                    <x-badge :value="$band->name" class="badge-sm badge-outline" />
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="divider my-0"></div>

            {{-- Commitment Info --}}
            <div>
                <h3 class="text-sm font-semibold text-base-content/60 uppercase tracking-wider mb-3">Commitment</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <div class="text-sm text-base-content/60">Event</div>
                        <div class="font-semibold text-sm mt-1">{{ $detailCommitment->event->name }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Status</div>
                        @php
                            $detailStatusClasses = match($detailCommitment->status) {
                                'committed' => 'badge-info',
                                'delivered' => 'badge-success',
                                'returned' => 'badge-neutral',
                                'cancelled' => 'badge-error',
                                'lost' => 'badge-error',
                                'damaged' => 'badge-error',
                                default => 'badge-ghost'
                            };
                        @endphp
                        <x-badge
                            value="{{ ucfirst(str_replace('_', ' ', $detailCommitment->status)) }}"
                            class="{{ $detailStatusClasses }} mt-1"
                        />
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Expected Delivery</div>
                        <div class="font-semibold text-sm mt-1">
                            @if($detailCommitment->expected_delivery_at)
                                {{ $detailCommitment->expected_delivery_at->format('M j, Y g:i A') }}
                            @else
                                <span class="text-base-content/40">Not set</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Station</div>
                        <div class="mt-1">
                            @if($detailCommitment->station)
                                <x-badge :value="$detailCommitment->station->name" class="badge-primary badge-sm" />
                            @else
                                <span class="text-sm text-base-content/40">Not assigned</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Committed</div>
                        <div class="font-semibold text-sm mt-1">
                            @if($detailCommitment->committed_at)
                                {{ $detailCommitment->committed_at->format('M j, Y g:i A') }}
                            @else
                                <span class="text-base-content/40">Unknown</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Delivery Notes --}}
            @if($detailCommitment->delivery_notes)
                <div>
                    <h3 class="text-sm font-semibold text-base-content/60 uppercase tracking-wider mb-2">Delivery Notes</h3>
                    <div class="text-sm bg-base-200 rounded-lg p-3 whitespace-pre-wrap">{{ $detailCommitment->delivery_notes }}</div>
                </div>
            @endif

            {{-- Manager Notes --}}
            @if($detailCommitment->manager_notes)
                <div>
                    <h3 class="text-sm font-semibold text-base-content/60 uppercase tracking-wider mb-2">Status History</h3>
                    <div class="text-sm bg-base-200 rounded-lg p-3 whitespace-pre-wrap font-mono text-xs">{{ $detailCommitment->manager_notes }}</div>
                </div>
            @endif

            {{-- Metadata --}}
            <div class="text-xs text-base-content/50 space-y-1">
                @if($detailCommitment->assignedBy)
                    <div>Assigned by {{ $detailCommitment->assignedBy->call_sign ?? $detailCommitment->assignedBy->first_name }}</div>
                @endif
                @if($detailCommitment->statusChangedBy && $detailCommitment->status_changed_at)
                    <div>Last status change by {{ $detailCommitment->statusChangedBy->call_sign ?? $detailCommitment->statusChangedBy->first_name }} on {{ $detailCommitment->status_changed_at->format('M j, Y g:i A') }}</div>
                @endif
            </div>
        </div>

        <x-slot:actions>
            <div class="flex flex-wrap gap-2 w-full justify-end">
                <x-dropdown>
                    <x-slot:trigger>
                        <x-button label="Change Status" icon="o-arrows-right-left" class="btn-primary btn-sm" />
                    </x-slot:trigger>
                    @foreach(\App\Models\EquipmentEvent::STATUSES as $status)
                        @if($status !== $detailCommitment->status)
                            <x-menu-item
                                title="{{ ucfirst(str_replace('_', ' ', $status)) }}"
                                wire:click="changeStatus({{ $detailCommitment->id }}, '{{ $status }}')"
                                spinner="changeStatus({{ $detailCommitment->id }}, '{{ $status }}')"
                            />
                        @endif
                    @endforeach
                </x-dropdown>

                <x-button
                    label="Update Notes"
                    icon="o-pencil"
                    class="btn-outline btn-sm"
                    wire:click="openNotesModal({{ $detailCommitment->id }})"
                />

                <x-button label="Close" @click="$wire.showDetailsModal = false" class="btn-ghost btn-sm" />
            </div>
        </x-slot:actions>
    </x-modal>
@endif
