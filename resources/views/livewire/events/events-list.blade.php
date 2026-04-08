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

    {{-- Mobile Card View --}}
    <div class="md:hidden space-y-3">
        @forelse($events as $event)
            <x-card wire:key="event-card-{{ $event->id }}" shadow class="{{ $event->deleted_at ? 'opacity-60' : '' }} !p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5 mb-1">
                            @if($event->status === 'active')
                                <span class="badge badge-success badge-sm"><x-icon name="o-play-circle" class="w-3 h-3 mr-1" />Active</span>
                            @elseif($event->status === 'setup')
                                <span class="badge badge-warning badge-sm"><x-icon name="o-wrench-screwdriver" class="w-3 h-3 mr-1" />Setup</span>
                            @elseif($event->status === 'upcoming')
                                <span class="badge badge-info badge-sm"><x-icon name="o-calendar" class="w-3 h-3 mr-1" />Upcoming</span>
                            @else
                                <span class="badge badge-neutral badge-sm"><x-icon name="o-check-badge" class="w-3 h-3 mr-1" />Completed</span>
                            @endif
                            @if($event->deleted_at)
                                <span class="badge badge-error badge-sm"><x-icon name="o-archive-box" class="w-3 h-3 mr-1" />Archived</span>
                            @endif
                        </div>
                        <div class="font-semibold">{{ $event->name }}</div>
                        <div class="text-xs opacity-60 mt-0.5">{{ $event->eventType->name ?? 'N/A' }}</div>
                    </div>
                    <div class="dropdown dropdown-end">
                        <button tabindex="0" class="btn btn-ghost btn-sm btn-square">
                            <x-icon name="o-ellipsis-vertical" class="w-5 h-5" />
                        </button>
                        <ul class="dropdown-content menu menu-sm z-[1000] p-2 shadow-lg bg-base-100 rounded-box w-52 border border-base-300 mt-1">
                            <li>
                                <a href="{{ route('events.show', $event) }}" wire:navigate>
                                    <x-icon name="o-eye" class="w-4 h-4" />
                                    View Details
                                </a>
                            </li>
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
                </div>

                <div class="mt-3 pt-3 border-t border-base-200 space-y-1.5">
                    <div class="flex items-center gap-2 text-sm">
                        <x-icon name="o-clock" class="w-4 h-4 opacity-50 shrink-0" />
                        <div>
                            <span>{{ $event->start_time ? toLocalTime($event->start_time)->format('M j, Y H:i T') : 'Not set' }}</span>
                            <span class="opacity-60 text-xs"> to {{ $event->end_time ? toLocalTime($event->end_time)->format('M j, Y H:i T') : 'Not set' }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <x-icon name="o-signal" class="w-4 h-4 opacity-50 shrink-0" />
                        <span class="font-mono">{{ $event->eventConfiguration->callsign ?? 'Not configured' }}</span>
                        @if($event->eventConfiguration?->operatingClass?->code)
                            <span class="badge badge-outline badge-xs">{{ $event->eventConfiguration->operatingClass->code }}</span>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-4 mt-3 pt-3 border-t border-base-200 text-sm">
                    <div class="flex flex-col items-center flex-1">
                        <span class="font-semibold">{{ number_format($event->contacts_count) }}</span>
                        <span class="text-xs opacity-50">Contacts</span>
                    </div>
                    <div class="flex flex-col items-center flex-1">
                        <span class="font-semibold">{{ number_format($event->participants_count) }}</span>
                        <span class="text-xs opacity-50">Participants</span>
                    </div>
                    <div class="flex flex-col items-center flex-1">
                        <span class="font-bold text-primary">{{ number_format($event->final_score) }}</span>
                        <span class="text-xs opacity-50">Score</span>
                    </div>
                </div>
            </x-card>
        @empty
            <div class="text-center py-8 text-base-content/60">
                <div class="flex flex-col items-center gap-2">
                    <x-icon name="o-calendar" class="w-12 h-12 opacity-50" />
                    <p>No events found</p>
                    @can('create-events')
                        <x-button label="Create First Event" icon="o-plus" class="btn-primary btn-sm mt-2" link="{{ route('events.create') }}" wire:navigate />
                    @endcan
                </div>
            </div>
        @endforelse

        @if($events->hasPages())
            <div class="pt-2">
                {{ $events->links() }}
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
                            <button wire:click="sortBy('name')" class="flex items-center gap-1 hover:underline">
                                Status & Name
                                @if($sortField === 'name')
                                    <x-icon :name="$sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down'" class="w-4 h-4" />
                                @endif
                            </button>
                        </th>
                        <th>Type</th>
                        <th>
                            <button wire:click="sortBy('start_time')" class="flex items-center gap-1 hover:underline">
                                Dates
                                @if($sortField === 'start_time')
                                    <x-icon :name="$sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down'" class="w-4 h-4" />
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
                                            <span class="badge badge-success badge-sm"><x-icon name="o-play-circle" class="w-4 h-4 mr-2" />Active</span>
                                        @elseif($event->status === 'setup')
                                            <span class="badge badge-warning badge-sm"><x-icon name="o-wrench-screwdriver" class="w-4 h-4 mr-2" />Setup</span>
                                        @elseif($event->status === 'upcoming')
                                            <span class="badge badge-info badge-sm"><x-icon name="o-calendar" class="w-4 h-4 mr-2" />Upcoming</span>
                                        @else
                                            <span class="badge badge-neutral badge-sm"><x-icon name="o-check-badge" class="w-4 h-4 mr-2" />Completed</span>
                                        @endif
                                        @if($event->deleted_at)
                                            <span class="badge badge-error badge-sm"><x-icon name="o-archive-box" class="w-4 h-4 mr-2" />Archived</span>
                                        @endif
                                    </div>
                                    <span class="font-semibold">{{ $event->name }}</span>
                                </div>
                            </td>
                            <td>{{ $event->eventType->name ?? 'N/A' }}</td>
                            <td>
                                <div class="text-sm">
                                    <div>{{ $event->start_time ? toLocalTime($event->start_time)->format('M j, Y H:i T') : 'Not set' }}</div>
                                    <div class="text-xs opacity-60">to {{ $event->end_time ? toLocalTime($event->end_time)->format('M j, Y H:i T') : 'Not set' }}</div>
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
                                    <ul class="dropdown-content menu menu-sm z-[1000] p-2 shadow-lg bg-base-100 rounded-box w-52 border border-base-300 mt-1">
                                        <li>
                                            <a href="{{ route('events.show', $event) }}" wire:navigate>
                                                <x-icon name="o-eye" class="w-4 h-4" />
                                                View Details
                                            </a>
                                        </li>

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
