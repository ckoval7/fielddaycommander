<div class="space-y-6">
    <!-- Header -->
    <x-header title="Events" subtitle="Manage Field Day and other contest events" separator progress-indicator>
        <x-slot:actions>
            @can('create-events')
                <x-button label="Create Event" icon="o-plus" class="btn-primary" link="{{ route('events.create') }}" wire:navigate responsive />
            @endcan
        </x-slot:actions>
    </x-header>

    <!-- Controls -->
    <div class="flex items-center justify-between gap-4">
        <div class="form-control">
            <label class="label cursor-pointer gap-2">
                <input type="checkbox" wire:model.live="showArchived" class="checkbox checkbox-sm" />
                <span class="label-text">Show Archived Events</span>
            </label>
        </div>
    </div>

    <!-- Events Table -->
    <x-card shadow>
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>
                            <button wire:click="sortBy('name')" class="flex items-center gap-1 hover:underline">
                                Status & Name
                                @if($sortField === 'name')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                @endif
                            </button>
                        </th>
                        <th>Type</th>
                        <th>
                            <button wire:click="sortBy('start_time')" class="flex items-center gap-1 hover:underline">
                                Dates
                                @if($sortField === 'start_time')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                @endif
                            </button>
                        </th>
                        <th>Callsign</th>
                        <th>Class</th>
                        <th class="text-center">Contacts</th>
                        <th class="text-center">Participants</th>
                        <th class="text-center">Score</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                        <tr wire:key="event-{{ $event->id }}" class="{{ $event->deleted_at ? 'opacity-60' : '' }}">
                            <td>
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center gap-2">
                                        @if($event->status === 'active')
                                            <span class="badge badge-success badge-sm">Active</span>
                                        @elseif($event->status === 'upcoming')
                                            <span class="badge badge-info badge-sm">Upcoming</span>
                                        @elseif($event->status === 'in_progress')
                                            <span class="badge badge-warning badge-sm">In Progress</span>
                                        @else
                                            <span class="badge badge-neutral badge-sm">Completed</span>
                                        @endif
                                        @if($event->deleted_at)
                                            <span class="badge badge-error badge-sm">Archived</span>
                                        @endif
                                    </div>
                                    <span class="font-semibold">{{ $event->name }}</span>
                                </div>
                            </td>
                            <td>{{ $event->eventType->name ?? 'N/A' }}</td>
                            <td>
                                <div class="text-sm">
                                    <div>{{ $event->start_time?->format('M j, Y H:i') ?? 'Not set' }}</div>
                                    <div class="text-xs opacity-60">to {{ $event->end_time?->format('M j, Y H:i') ?? 'Not set' }}</div>
                                </div>
                            </td>
                            <td>
                                <span class="font-mono text-sm">{{ $event->eventConfiguration->callsign ?? 'Not configured' }}</span>
                            </td>
                            <td>{{ $event->eventConfiguration?->operatingClass?->code ?? 'N/A' }}</td>
                            <td class="text-center">
                                <span class="font-semibold">{{ number_format($event->contacts_count) }}</span>
                            </td>
                            <td class="text-center">
                                <span class="font-semibold">{{ number_format($event->participants_count) }}</span>
                            </td>
                            <td class="text-center">
                                <span class="font-bold text-primary">{{ number_format($event->final_score) }}</span>
                            </td>
                            <td class="text-right">
                                <div class="dropdown dropdown-end">
                                    <button tabindex="0" class="btn btn-ghost btn-sm btn-square">
                                        <x-icon name="o-ellipsis-vertical" class="w-5 h-5" />
                                    </button>
                                    <ul tabindex="0" class="dropdown-content menu menu-sm z-[100] p-2 shadow-lg bg-base-100 rounded-box w-52 border border-base-300">
                                        {{-- Future: Add when events.show route exists
                                        <li>
                                            <a href="{{ route('events.show', $event->id) }}" wire:navigate>
                                                <x-icon name="o-eye" class="w-4 h-4" />
                                                View Details
                                            </a>
                                        </li>
                                        --}}

                                        @can('edit-events')
                                            @if(!$event->deleted_at)
                                                <li>
                                                    <a href="{{ route('events.edit', ['eventId' => $event->id]) }}" wire:navigate>
                                                        <x-icon name="o-pencil" class="w-4 h-4" />
                                                        Edit
                                                    </a>
                                                </li>
                                            @endif
                                        @endcan

                                        @can('activate-events')
                                            @if(!$event->deleted_at && $event->status !== 'active')
                                                <li>
                                                    <a wire:click.prevent="activate({{ $event->id }})"
                                                       wire:confirm="Are you sure you want to set '{{ $event->name }}' as the active event?"
                                                       class="cursor-pointer">
                                                        <x-icon name="o-check-circle" class="w-4 h-4" />
                                                        Set as Active
                                                    </a>
                                                </li>
                                            @endif
                                        @endcan

                                        @can('create-events')
                                            @if(!$event->deleted_at)
                                                <li>
                                                    <a href="{{ route('events.clone', ['eventId' => $event->id]) }}" wire:navigate>
                                                        <x-icon name="o-document-duplicate" class="w-4 h-4" />
                                                        Clone Event
                                                    </a>
                                                </li>
                                            @endif
                                        @endcan

                                        @can('delete-events')
                                            @if(!$event->deleted_at)
                                                @can('activate-events')
                                                    <div class="divider my-1"></div>
                                                @endcan
                                                <li>
                                                    <a wire:click.prevent="delete({{ $event->id }})"
                                                       wire:confirm="Are you sure you want to delete '{{ $event->name }}'? {{ $event->eventConfiguration?->hasContacts() ? 'This event has contacts and will be archived (soft deleted).' : 'This event will be permanently deleted.' }}"
                                                       class="text-error cursor-pointer">
                                                        <x-icon name="o-trash" class="w-4 h-4" />
                                                        Delete
                                                    </a>
                                                </li>
                                            @endif
                                        @endcan
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-8 text-base-content/60">
                                <div class="flex flex-col items-center gap-2">
                                    <x-icon name="o-calendar" class="w-12 h-12 opacity-50" />
                                    <p>No events found</p>
                                    @can('create-events')
                                        <x-button label="Create First Event" icon="o-plus" class="btn-primary btn-sm mt-2" link="{{ route('events.create') }}" wire:navigate />
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($events->hasPages())
            <div class="mt-4">
                {{ $events->links() }}
            </div>
        @endif
    </x-card>
</div>
