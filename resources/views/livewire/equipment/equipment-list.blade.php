<div>
    <x-slot:title>Equipment</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6">
            <h1 class="text-3xl font-bold">My Equipment</h1>
            <div class="flex gap-2">
                <x-button label="Add Equipment" icon="phosphor-plus" class="btn-primary btn-sm sm:btn-md" link="{{ route('equipment.create') }}" wire:navigate />
                @can('edit-any-equipment')
                    <x-button label="Add Club Equipment" icon="phosphor-buildings" class="btn-club btn-sm sm:btn-md" link="{{ route('equipment.create', ['club' => true]) }}" wire:navigate />
                @endcan
            </div>
        </div>

        <x-equipment.filters />

        {{-- Bulk Action Bar --}}
        @if(count($selectedIds) > 0)
            <div class="flex flex-wrap items-center gap-3 mb-4 p-3 bg-primary/10 rounded-lg border border-primary/20">
                <span class="text-sm font-semibold">{{ count($selectedIds) }} item(s) selected</span>
                <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                    @php
                        $allAvailable = \App\Models\Equipment::whereIn('id', $selectedIds)
                            ->whereDoesntHave('commitments', function ($q) {
                                $q->whereIn('status', ['committed', 'delivered'])
                                    ->whereHas('event', function ($eq) {
                                        $eq->where('start_time', '<=', now()->addDays(30))
                                            ->where('end_time', '>=', now());
                                    });
                            })
                            ->count() === count($selectedIds);
                    @endphp
                    <x-button
                        label="Commit to Event"
                        icon="phosphor-calendar"
                        class="btn-primary btn-sm"
                        wire:click="openBulkCommitModal"
                        :disabled="!$allAvailable"
                        :title="$allAvailable ? '' : 'Some selected items already have active commitments'"
                    />
                    <x-button
                        label="Delete"
                        icon="phosphor-trash"
                        class="btn-error btn-sm"
                        wire:click="bulkDeleteEquipment"
                        wire:confirm="Are you sure you want to delete {{ count($selectedIds) }} item(s)?"
                    />
                    <x-button
                        label="Clear"
                        icon="phosphor-x"
                        class="btn-ghost btn-sm ml-auto sm:ml-0"
                        wire:click="deselectAll"
                    />
                </div>
            </div>
        @endif

        {{-- Mobile Card View --}}
        <div class="md:hidden space-y-3">
            @forelse($equipment as $item)
                <x-card wire:key="equipment-card-{{ $item->id }}" shadow class="!p-4">
                    <div class="flex items-start gap-3">
                        <label class="flex items-center pt-1">
                            <span class="sr-only">Select {{ $item->make }} {{ $item->model }}</span>
                            <input type="checkbox" class="checkbox checkbox-sm" value="{{ $item->id }}" wire:model.live="selectedIds" />
                        </label>
                        <x-equipment.photo-thumbnail :item="$item" size="lg" />
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="font-semibold">{{ $item->make }}</div>
                                    <div class="text-sm opacity-60">{{ $item->model }}</div>
                                </div>
                                <x-dropdown>
                                    <x-slot:trigger>
                                        <x-button icon="phosphor-dots-three-vertical" class="btn-sm btn-ghost" />
                                    </x-slot:trigger>
                                    <x-menu-item title="Edit" icon="phosphor-pencil-simple" link="{{ route('equipment.edit', $item) }}" wire:navigate />
                                    @if($item->currentCommitment)
                                        <x-menu-separator />
                                        <x-menu-item title="View Commitment" icon="phosphor-eye" wire:click="openDetailsModal({{ $item->currentCommitment->id }})" />
                                        <x-menu-item title="Update Notes" icon="phosphor-note-pencil" wire:click="openNotesModal({{ $item->currentCommitment->id }})" />
                                        <x-menu-separator />
                                        @foreach(\App\Models\EquipmentEvent::STATUSES as $status)
                                            @if($status !== $item->currentCommitment->status)
                                                <x-menu-item
                                                    title="{{ ucfirst(str_replace('_', ' ', $status)) }}"
                                                    wire:click="changeStatus({{ $item->currentCommitment->id }}, '{{ $status }}')"
                                                />
                                            @endif
                                        @endforeach
                                    @else
                                        <x-menu-item title="Commit to Event" icon="phosphor-calendar" wire:click="openCommitModal({{ $item->id }})" />
                                    @endif
                                    <x-menu-separator />
                                    <x-menu-item title="Delete" icon="phosphor-trash" class="text-error" wire:click="deleteEquipment({{ $item->id }})" wire:confirm="Are you sure you want to delete this equipment?" />
                                </x-dropdown>
                            </div>
                            @if($item->is_club_equipment)
                                <span class="badge badge-club badge-xs mt-1">
                                    <x-icon name="phosphor-buildings" class="w-3 h-3 mr-0.5" />
                                    Club Equipment
                                </span>
                                @if($item->managed_by_user_id && $item->manager)
                                    <div class="text-xs opacity-70">Managed by {{ $item->manager->full_name }}</div>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-base-200">
                        <span class="badge badge-primary badge-sm">{{ ucfirst(str_replace('_', ' ', $item->type)) }}</span>
                        <x-equipment.status-badge :status="$item->currentCommitment?->status ?? 'available'" />
                        @if($item->serial_number)
                            <code class="text-xs opacity-70">{{ $item->serial_number }}</code>
                        @endif
                    </div>
                    @if($item->tags && is_array($item->tags) && count($item->tags))
                        <div class="mt-2">
                            <x-equipment.tags :tags="$item->tags" size="xs" />
                        </div>
                    @endif
                </x-card>
            @empty
                <x-equipment.empty-state message="No equipment found" action-label="Create First Equipment" :action-route="route('equipment.create')" />
            @endforelse

            @if($equipment->hasPages())
                <div class="pt-4">
                    {{ $equipment->links() }}
                </div>
            @endif
        </div>

        {{-- Desktop Table View --}}
        <x-card shadow class="hidden md:block">
            <div>
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>
                                <label>
                                    <span class="sr-only">Select all</span>
                                    <input
                                        type="checkbox"
                                        class="checkbox checkbox-sm"
                                        x-on:change="
                                            if ($el.checked) {
                                                $wire.selectAll();
                                            } else {
                                                $wire.deselectAll();
                                            }
                                        "
                                        :checked="$wire.selectedIds.length > 0 && $wire.selectedIds.length === $wire.equipment?.data?.length"
                                    />
                                </label>
                            </th>
                            <th>Photo</th>
                            <th>Make/Model</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Serial Number</th>
                            <th>Status</th>
                            <th>Tags</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($equipment as $item)
                            <tr wire:key="equipment-{{ $item->id }}">
                                <td>
                                    <label>
                                        <span class="sr-only">Select {{ $item->make }} {{ $item->model }}</span>
                                        <input
                                            type="checkbox"
                                            class="checkbox checkbox-sm"
                                            value="{{ $item->id }}"
                                            wire:model.live="selectedIds"
                                        />
                                    </label>
                                </td>
                                <td>
                                    <x-equipment.photo-thumbnail :item="$item" />
                                </td>
                                <td class="font-semibold">
                                    <div>{{ $item->make }}</div>
                                    <div class="text-sm opacity-60">{{ $item->model }}</div>
                                    @if($item->is_club_equipment)
                                        <div class="mt-1">
                                            <span class="badge badge-club badge-xs">
                                                <x-icon name="phosphor-buildings" class="w-3 h-3 mr-0.5" />
                                                Club Equipment
                                            </span>
                                        </div>
                                        @if($item->managed_by_user_id && $item->manager)
                                            <div class="text-xs opacity-70 mt-0.5">
                                                Managed by {{ $item->manager->full_name }}
                                            </div>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-primary badge-sm">
                                        {{ ucfirst(str_replace('_', ' ', $item->type)) }}
                                    </span>
                                </td>
                                <td class="max-w-xs">
                                    <p class="truncate text-sm">{{ $item->description ?? '-' }}</p>
                                </td>
                                <td>
                                    <code class="text-xs">{{ $item->serial_number ?? '-' }}</code>
                                </td>
                                <td>
                                    <x-equipment.status-badge :status="$item->currentCommitment?->status ?? 'available'" />
                                </td>
                                <td>
                                    <x-equipment.tags :tags="$item->tags" />
                                </td>
                                <td class="text-right">
                                    <x-dropdown>
                                        <x-slot:trigger>
                                            <x-button icon="phosphor-dots-three-vertical" class="btn-sm btn-ghost" />
                                        </x-slot:trigger>

                                        <x-menu-item title="Edit" icon="phosphor-pencil-simple" link="{{ route('equipment.edit', $item) }}" wire:navigate />

                                        @if($item->currentCommitment)
                                            <x-menu-separator />
                                            <x-menu-item title="View Commitment" icon="phosphor-eye" wire:click="openDetailsModal({{ $item->currentCommitment->id }})" />
                                            <x-menu-item title="Update Notes" icon="phosphor-note-pencil" wire:click="openNotesModal({{ $item->currentCommitment->id }})" />
                                            <x-menu-separator />
                                            @foreach(\App\Models\EquipmentEvent::STATUSES as $status)
                                                @if($status !== $item->currentCommitment->status)
                                                    <x-menu-item
                                                        title="{{ ucfirst(str_replace('_', ' ', $status)) }}"
                                                        wire:click="changeStatus({{ $item->currentCommitment->id }}, '{{ $status }}')"
                                                    />
                                                @endif
                                            @endforeach
                                        @else
                                            <x-menu-item title="Commit to Event" icon="phosphor-calendar" wire:click="openCommitModal({{ $item->id }})" />
                                        @endif

                                        <x-menu-separator />
                                        <x-menu-item title="Delete" icon="phosphor-trash" class="text-error" wire:click="deleteEquipment({{ $item->id }})" wire:confirm="Are you sure you want to delete this equipment?" />
                                    </x-dropdown>
                                </td>
                            </tr>
                        @empty
                            <x-equipment.empty-state colspan="9" message="No equipment found" action-label="Create First Equipment" :action-route="route('equipment.create')" />
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($equipment->hasPages())
                <div class="p-4">
                    {{ $equipment->links() }}
                </div>
            @endif
        </x-card>
    </div>

    <x-equipment.photo-modal :photoPath="$photoPath" :photoDescription="$photoDescription" />

    {{-- Commit Equipment Modal --}}
    <x-modal wire:model="showCommitModal" title="Commit Equipment to Event" class="backdrop-blur">
        <x-form wire:submit="commitEquipment" class="space-y-4">
            <x-select
                label="Event"
                wire:model="commitEventId"
                icon="phosphor-calendar"
                placeholder="Select an event..."
                :options="$this->upcomingEvents->map(fn($e) => [
                    'value' => $e->id,
                    'label' => $e->name . ' (' . $e->start_time->format('M j, Y') . ')'
                ])->toArray()"
                option-value="value"
                option-label="label"
            />

            <x-flatpickr
                label="Expected Delivery"
                wire:model="commitExpectedDeliveryAt"
                mode="date"
                icon="phosphor-calendar"
                hint="When do you expect to deliver this equipment?"
            />

            <x-textarea
                label="Delivery Notes"
                wire:model="commitDeliveryNotes"
                placeholder="Add any special instructions or notes about delivery..."
                hint="Maximum 500 characters"
                rows="4"
            />

            @if($errors->has('commitEquipmentId') || $errors->has('commitEventId') || $errors->has('commitExpectedDeliveryAt') || $errors->has('commitDeliveryNotes'))
                <div class="alert alert-error">
                    <x-icon name="phosphor-warning" class="w-5 h-5" />
                    <ul class="list-disc list-inside">
                        @foreach(['commitEquipmentId', 'commitEventId', 'commitExpectedDeliveryAt', 'commitDeliveryNotes'] as $field)
                            @error($field)
                                <li>{{ $message }}</li>
                            @enderror
                        @endforeach
                    </ul>
                </div>
            @endif

            <x-slot:actions>
                <x-button label="Cancel" wire:click="$set('showCommitModal', false)" class="btn-ghost" />
                <x-button label="Commit Equipment" type="submit" class="btn-primary" spinner="commitEquipment" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    {{-- Bulk Commit Modal --}}
    <x-modal wire:model="showBulkCommitModal" title="Commit {{ count($selectedIds) }} Item(s) to Event" class="backdrop-blur">
        <x-form wire:submit="bulkCommitEquipment" class="space-y-4">
            <x-select
                label="Event"
                wire:model="bulkCommitEventId"
                icon="phosphor-calendar"
                placeholder="Select an event..."
                :options="$this->upcomingEvents->map(fn($e) => [
                    'value' => $e->id,
                    'label' => $e->name . ' (' . $e->start_time->format('M j, Y') . ')'
                ])->toArray()"
                option-value="value"
                option-label="label"
            />

            <x-flatpickr
                label="Expected Delivery"
                wire:model="bulkCommitExpectedDeliveryAt"
                mode="date"
                icon="phosphor-calendar"
                hint="When do you expect to deliver this equipment?"
            />

            <x-textarea
                label="Delivery Notes"
                wire:model="bulkCommitDeliveryNotes"
                placeholder="Add any special instructions or notes about delivery..."
                hint="Maximum 500 characters"
                rows="4"
            />

            @error('bulkCommit')
                <div class="alert alert-error">
                    <x-icon name="phosphor-warning" class="w-5 h-5" />
                    <span>{{ $message }}</span>
                </div>
            @enderror

            <x-slot:actions>
                <x-button label="Cancel" wire:click="$set('showBulkCommitModal', false)" class="btn-ghost" />
                <x-button label="Commit All" type="submit" class="btn-primary" spinner="bulkCommitEquipment" />
            </x-slot:actions>
        </x-form>
    </x-modal>

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
                                <x-icon name="phosphor-wrench" class="w-8 h-8 text-base-content/50" />
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
                            <x-button label="Change Status" icon="phosphor-arrows-left-right" class="btn-primary btn-sm" />
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
                        icon="phosphor-pencil-simple"
                        class="btn-outline btn-sm"
                        wire:click="openNotesModal({{ $detailCommitment->id }})"
                    />

                    <x-button label="Close" @click="$wire.showDetailsModal = false" class="btn-ghost btn-sm" />
                </div>
            </x-slot:actions>
        </x-modal>
    @endif

    {{-- Update Notes Modal --}}
    <x-modal wire:model="showNotesModal" title="Update Delivery Notes" class="backdrop-blur">
        <x-form wire:submit="updateNotes" class="space-y-4">
            <x-textarea
                label="Delivery Notes"
                wire:model="tempNotes"
                placeholder="Add or update delivery notes..."
                hint="Maximum 500 characters"
                rows="4"
            />

            <x-slot:actions>
                <x-button label="Cancel" wire:click="$set('showNotesModal', false)" class="btn-ghost" />
                <x-button
                    label="Update Notes"
                    type="submit"
                    class="btn-primary"
                    spinner="updateNotes"
                    wire:click="updateNotes({{ $updateNoteId }}, $wire.tempNotes)"
                />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
