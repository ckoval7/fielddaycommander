<div class="space-y-6">
    <x-slot:title>Event Equipment Commitments</x-slot:title>

    {{-- Page Header --}}
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div class="flex-1">
            <h1 class="text-3xl font-bold">Event Equipment Commitments</h1>
            <p class="text-base-content/60 mt-2">Manage your equipment commitments for upcoming events</p>
        </div>
    </div>

    {{-- Events Tabs --}}
    @if($events && count($events) > 0)
        <x-tabs wire:model="selectedTab">
            @foreach($events as $event)
                <x-tab
                    name="event-{{ $event->id }}"
                    label="{{ $event->name }}"
                    icon="o-calendar"
                >
                    <div class="mt-6 space-y-6">
                        {{-- Event Details Card --}}
                        <x-card title="Event Details" shadow>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div>
                                    <div class="text-sm text-base-content/60">Event Name</div>
                                    <div class="font-semibold">{{ $event->name }}</div>
                                </div>
                                <div>
                                    <div class="text-sm text-base-content/60">Start Date</div>
                                    <div class="font-semibold">{{ $event->start_time?->format('M j, Y g:i A') ?? 'TBD' }}</div>
                                </div>
                                <div>
                                    <div class="text-sm text-base-content/60">End Date</div>
                                    <div class="font-semibold">{{ $event->end_time?->format('M j, Y g:i A') ?? 'TBD' }}</div>
                                </div>
                                <div>
                                    <div class="text-sm text-base-content/60">Status</div>
                                    <div class="mt-1">
                                        @if($event->status === 'upcoming')
                                            <x-badge value="Upcoming" class="badge-info" />
                                        @elseif($event->status === 'active')
                                            <x-badge value="Active" class="badge-success" />
                                        @elseif($event->status === 'in_progress')
                                            <x-badge value="In Progress" class="badge-warning" />
                                        @else
                                            <x-badge value="Completed" class="badge-neutral" />
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </x-card>

                        {{-- Section 1: My Commitments --}}
                        <x-card title="My Commitments" shadow>
                            @php
                                $eventCommitments = $commitments->filter(function ($c) use ($event) {
                                    return $c->event_id == $event->id;
                                });
                            @endphp

                            @if($eventCommitments && count($eventCommitments) > 0)
                                <div class="overflow-x-auto">
                                    <table class="table table-zebra">
                                        <thead>
                                            <tr>
                                                <th>Equipment</th>
                                                <th>Status</th>
                                                <th>Expected Delivery</th>
                                                <th>Station</th>
                                                <th>Notes</th>
                                                <th class="text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($eventCommitments as $commitment)
                                                <tr wire:key="commitment-{{ $commitment->id }}" class="hover:bg-base-200/50 transition-colors">
                                                    {{-- Equipment Info --}}
                                                    <td>
                                                        <div class="flex items-center gap-3">
                                                            @if($commitment->equipment->photo_path)
                                                                <img
                                                                    src="{{ asset('storage/' . $commitment->equipment->photo_path) }}"
                                                                    alt="Equipment"
                                                                    class="w-10 h-10 object-cover rounded cursor-pointer hover:opacity-80 transition-opacity"
                                                                    wire:click="viewPhoto('{{ $commitment->equipment->photo_path }}', '{{ $commitment->equipment->make }} {{ $commitment->equipment->model }}')"
                                                                />
                                                            @else
                                                                <div class="w-10 h-10 bg-base-300 rounded flex items-center justify-center">
                                                                    <x-icon name="o-wrench-screwdriver" class="w-5 h-5 text-base-content/50" />
                                                                </div>
                                                            @endif
                                                            <div>
                                                                <div class="font-semibold">{{ $commitment->equipment->make }} {{ $commitment->equipment->model }}</div>
                                                                <div class="text-xs text-base-content/60">{{ ucfirst(str_replace('_', ' ', $commitment->equipment->type)) }}</div>
                                                            </div>
                                                        </div>
                                                    </td>

                                                    {{-- Status Badge --}}
                                                    <td>
                                                        @php
                                                            $statusClasses = match($commitment->status) {
                                                                'committed' => 'badge-info',
                                                                'delivered' => 'badge-success',
                                                                'in_use' => 'badge-warning',
                                                                'returned' => 'badge-neutral',
                                                                'cancelled' => 'badge-error',
                                                                'lost' => 'badge-error',
                                                                'damaged' => 'badge-error',
                                                                default => 'badge-ghost'
                                                            };
                                                        @endphp
                                                        <x-badge
                                                            value="{{ ucfirst(str_replace('_', ' ', $commitment->status)) }}"
                                                            class="{{ $statusClasses }}"
                                                        />
                                                    </td>

                                                    {{-- Expected Delivery --}}
                                                    <td>
                                                        @if($commitment->expected_delivery_at)
                                                            <div class="text-sm font-semibold">{{ $commitment->expected_delivery_at->format('M j, Y') }}</div>
                                                            <div class="text-xs text-base-content/60">{{ $commitment->expected_delivery_at->format('g:i A') }}</div>
                                                        @else
                                                            <span class="text-xs text-base-content/60">-</span>
                                                        @endif
                                                    </td>

                                                    {{-- Station Assignment --}}
                                                    <td>
                                                        @if($commitment->station_id)
                                                            <span class="badge badge-primary badge-sm">
                                                                {{ $commitment->station->name ?? 'Unknown' }}
                                                            </span>
                                                        @else
                                                            <span class="text-xs text-base-content/60">Not assigned</span>
                                                        @endif
                                                    </td>

                                                    {{-- Notes Preview --}}
                                                    <td>
                                                        @if($commitment->delivery_notes)
                                                            <div class="text-xs text-base-content/70 max-w-xs truncate" title="{{ $commitment->delivery_notes }}">
                                                                {{ $commitment->delivery_notes }}
                                                            </div>
                                                        @else
                                                            <span class="text-xs text-base-content/60">-</span>
                                                        @endif
                                                    </td>

                                                    {{-- Actions --}}
                                                    <td class="text-right">
                                                        <x-dropdown>
                                                            <x-slot:trigger>
                                                                <x-button icon="o-ellipsis-vertical" class="btn-sm btn-ghost" />
                                                            </x-slot:trigger>

                                                            {{-- Mark as Delivered (only if committed) --}}
                                                            @if($commitment->status === 'committed')
                                                                <x-menu-item
                                                                    title="Mark as Delivered"
                                                                    icon="o-check-circle"
                                                                    wire:click="markAsDelivered({{ $commitment->id }})"
                                                                    spinner="markAsDelivered({{ $commitment->id }})"
                                                                />
                                                            @endif

                                                            {{-- Cancel (only if not in_use) --}}
                                                            @if($commitment->status !== 'in_use' && !in_array($commitment->status, ['cancelled', 'returned', 'lost', 'damaged']))
                                                                <x-menu-item
                                                                    title="Cancel"
                                                                    icon="o-x-mark"
                                                                    class="text-warning"
                                                                    wire:click="cancelCommitment({{ $commitment->id }})"
                                                                    wire:confirm="Are you sure you want to cancel this commitment?"
                                                                    spinner="cancelCommitment({{ $commitment->id }})"
                                                                />
                                                            @endif

                                                            {{-- Update Notes --}}
                                                            <x-menu-item
                                                                title="Update Notes"
                                                                icon="o-pencil"
                                                                @click="$dispatch('open-notes-modal', { commitmentId: {{ $commitment->id }}, notes: {{ json_encode($commitment->delivery_notes) }} })"
                                                            />

                                                            {{-- View Details --}}
                                                            <x-menu-separator />
                                                            <x-menu-item
                                                                title="View Details"
                                                                icon="o-eye"
                                                                @click="$dispatch('open-details-modal', { commitmentId: {{ $commitment->id }} })"
                                                            />
                                                        </x-dropdown>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <div class="text-center py-12 text-base-content/60">
                                    <x-icon name="o-inbox" class="w-16 h-16 mx-auto opacity-50 mb-4" />
                                    <p class="text-lg font-semibold mb-2">No Commitments Yet</p>
                                    <p class="text-sm mb-6">You haven't committed any equipment to this event</p>
                                    <x-button
                                        label="Commit Equipment"
                                        icon="o-plus"
                                        class="btn-primary btn-sm"
                                        wire:click="openCommitModal"
                                    />
                                </div>
                            @endif
                        </x-card>

                        {{-- Section 2: Commit Equipment --}}
                        <x-card title="Commit Equipment to This Event" shadow>
                            @if($userEquipment && count($userEquipment) > 0)
                                <div class="flex justify-center">
                                    <x-button
                                        label="Commit Equipment"
                                        icon="o-plus"
                                        class="btn-primary"
                                        wire:click="openCommitModal"
                                    />
                                </div>
                            @else
                                <div class="text-center py-8 text-base-content/60">
                                    <x-icon name="o-wrench-screwdriver" class="w-12 h-12 mx-auto opacity-50 mb-2" />
                                    <p class="text-sm">You don't have any equipment to commit</p>
                                    <x-button
                                        label="Create Equipment"
                                        icon="o-plus"
                                        class="btn-primary btn-sm mt-4"
                                        link="{{ route('equipment.create') }}"
                                        wire:navigate
                                    />
                                </div>
                            @endif
                        </x-card>
                    </div>
                </x-tab>
            @endforeach
        </x-tabs>
    @else
        {{-- No Events Available --}}
        <x-card shadow>
            <div class="text-center py-12 text-base-content/60">
                <x-icon name="o-calendar" class="w-16 h-16 mx-auto opacity-50 mb-4" />
                <p class="text-lg font-semibold mb-2">No Upcoming Events</p>
                <p class="text-sm">There are no events scheduled for the next 30 days</p>
            </div>
        </x-card>
    @endif

    {{-- Commit Equipment Modal --}}
    <x-modal wire:model="showCommitModal" title="Commit Equipment to {{ $selectedEventId ? 'Event' : 'Event' }}" class="backdrop-blur">
        <x-form wire:submit="commitEquipment" class="space-y-4">
            {{-- Equipment Selection --}}
            <x-select
                label="Equipment"
                wire:model="equipmentId"
                icon="o-wrench-screwdriver"
                placeholder="Select equipment to commit..."
                :options="$userEquipment->map(fn($eq) => [
                    'value' => $eq->id,
                    'label' => $eq->make . ' ' . $eq->model . ' (' . ucfirst(str_replace('_', ' ', $eq->type)) . ')'
                ])->toArray()"
                option-value="value"
                option-label="label"
            />

            {{-- Expected Delivery Date/Time --}}
            <x-flatpickr
                label="Expected Delivery"
                wire:model="expectedDeliveryAt"
                mode="date"
                icon="o-calendar"
                hint="When do you expect to deliver this equipment?"
            />

            {{-- Delivery Notes --}}
            <x-textarea
                label="Delivery Notes"
                wire:model="deliveryNotes"
                placeholder="Add any special instructions or notes about delivery..."
                hint="Maximum 500 characters"
                rows="4"
            />

            {{-- Form Actions --}}
            <x-slot:actions>
                <x-button
                    label="Cancel"
                    wire:click="$set('showCommitModal', false)"
                    class="btn-ghost"
                />
                <x-button
                    label="Commit Equipment"
                    type="submit"
                    class="btn-primary"
                    spinner="commitEquipment"
                />
            </x-slot:actions>
        </x-form>
    </x-modal>

    {{-- Update Notes Modal --}}
    <x-modal
        wire:model="showNotesModal"
        title="Update Delivery Notes"
        class="backdrop-blur"
        @open-notes-modal="showNotesModal = true; updateNoteId = $event.detail.commitmentId; tempNotes = $event.detail.notes"
    >
        <x-form wire:submit="updateNotes" class="space-y-4">
            <x-textarea
                label="Delivery Notes"
                wire:model="tempNotes"
                placeholder="Add or update delivery notes..."
                hint="Maximum 500 characters"
                rows="4"
            />

            <x-slot:actions>
                <x-button
                    label="Cancel"
                    wire:click="$set('showNotesModal', false)"
                    class="btn-ghost"
                />
                <x-button
                    label="Update Notes"
                    type="submit"
                    class="btn-primary"
                    spinner="updateNotes"
                    wire:click="updateNotes({{ $updateNoteId }}, tempNotes)"
                />
            </x-slot:actions>
        </x-form>
    </x-modal>

    {{-- Photo Viewer Modal --}}
    <x-modal wire:model="showPhotoModal" title="{{ $photoDescription ?? 'Equipment Detail' }}" class="backdrop-blur" box-class="max-w-4xl">
        @if($photoPath)
            <div class="flex justify-center items-center">
                <img
                    src="{{ asset('storage/' . $photoPath) }}"
                    alt="{{ $photoDescription ?? 'Equipment' }}"
                    class="max-w-full max-h-[70vh] object-contain rounded"
                />
            </div>
        @endif

        <x-slot:actions>
            <x-button label="Close" @click="$wire.showPhotoModal = false" class="btn-ghost" />
        </x-slot:actions>
    </x-modal>

    {{-- Hidden Test Elements --}}
    <div style="display: none;">
        @foreach($events as $event)
            <span class="test-event" data-event-id="{{ $event->id }}">{{ $event->name }}</span>
        @endforeach

        @foreach($commitments as $commitment)
            <span class="test-commitment" data-commitment-id="{{ $commitment->id }}" data-status="{{ $commitment->status }}">
                {{ $commitment->equipment->make }} {{ $commitment->equipment->model }}
            </span>
        @endforeach
    </div>
</div>
