<div wire:poll.visible.60s>
    <x-slot:title>Shift Schedule{{ $event ? ' - ' . $event->name : '' }}</x-slot:title>

    <div class="p-4 md:p-6">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold">Shift Schedule</h1>
                @if($event)
                    <p class="text-base-content/60">{{ $event->name }}</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @can('manage-shifts')
                    <x-button
                        label="Manage Schedule"
                        icon="phosphor-gear-six"
                        class="btn-outline"
                        link="{{ route('schedule.manage') }}"
                    />
                @endcan
                <x-button
                    label="My Shifts"
                    icon="phosphor-calendar-dots"
                    class="btn-outline"
                    link="{{ route('schedule.my-shifts') }}"
                />
            </div>
        </div>

        @if($eventConfig)
            @include('livewire.schedule.partials.filter-bar', [
                'showSearch' => true,
                'showTimeFilter' => true,
                'showStatusFilter' => false,
                'showAvailability' => true,
                'statuses' => [],
            ])
        @endif

        @if(!$eventConfig)
            <x-alert icon="phosphor-info" class="alert-info">
                No event is currently selected. Please select an event to view the schedule.
            </x-alert>
        @else
            @php
                $shiftsByRole = $this->shiftsByRole;
                $myAssignmentIds = $this->myAssignments->pluck('shift_id')->toArray();
                $myAssignmentsKeyed = $this->myAssignments->keyBy('shift_id');
            @endphp

            @if($this->filteredShifts->isEmpty())
                @if($this->activeFilterCount > 0)
                    <div class="text-center py-12">
                        <x-icon name="phosphor-funnel" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
                        <h3 class="text-lg font-semibold mb-2">No shifts match your filters</h3>
                        <p class="text-base-content/60 mb-4">Try adjusting your filters to see more shifts.</p>
                        <x-button
                            label="Clear Filters"
                            icon="phosphor-x"
                            class="btn-outline btn-sm"
                            wire:click="resetFilters"
                        />
                    </div>
                @else
                    <div class="text-center py-12">
                        <x-icon name="phosphor-calendar" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
                        <h3 class="text-lg font-semibold mb-2">No roles configured</h3>
                        <p class="text-base-content/60">Shift roles have not been set up for this event yet.</p>
                    </div>
                @endif
            @elseif($this->isFlattened)
                <div class="space-y-3" wire:loading.class="opacity-50">
                    @foreach($this->filteredShifts as $shift)
                        @php
                            $filledCount = $shift->assignments->count();
                            $isFull = $filledCount >= $shift->capacity;
                            $isMyShift = in_array($shift->id, $myAssignmentIds);
                            $myAssignment = $myAssignmentsKeyed->get($shift->id);
                            $isUrgentlyEmpty = $shift->is_urgently_empty;
                        @endphp

                        <div class="flex flex-col md:flex-row md:items-center gap-3 p-3 rounded-lg {{ $isUrgentlyEmpty ? 'bg-warning/5 border border-warning border-l-4' : 'bg-base-200/50 border border-base-300' }}">
                            {{-- Role Badge --}}
                            <div class="flex-shrink-0">
                                <span class="badge badge-sm text-white gap-1" style="background-color: {{ $shift->shiftRole?->color ?? '#64748b' }}">
                                    @if($shift->shiftRole?->icon)
                                        <x-icon :name="$shift->shiftRole->icon" class="w-3 h-3" />
                                    @endif
                                    {{ $shift->shiftRole?->name }}
                                </span>
                            </div>

                            {{-- Time Range --}}
                            @php
                                $startLocal = toLocalTime($shift->start_time);
                                $endLocal = toLocalTime($shift->end_time);
                                $spansDays = $startLocal->format('Y-m-d') !== $endLocal->format('Y-m-d');
                            @endphp
                            <div class="flex-shrink-0 min-w-[160px]">
                                <div class="font-medium text-sm">
                                    {{ $startLocal->format('M j, ' . timeFormat() . ' T') }}
                                </div>
                                <div class="text-xs text-base-content/60">
                                    to {{ $spansDays ? $endLocal->format('M j, ' . timeFormat() . ' T') : $endLocal->format(timeFormat() . ' T') }}
                                </div>
                            </div>

                            {{-- Capacity Indicator --}}
                            <div class="flex-shrink-0">
                                <x-badge
                                    :value="$filledCount . '/' . $shift->capacity . ' filled'"
                                    :class="$isFull ? 'badge-error badge-sm' : 'badge-success badge-sm'"
                                />
                                @if(!$shift->is_open)
                                    <x-badge value="Closed" class="badge-neutral badge-sm ml-1" />
                                @endif
                                @if($isUrgentlyEmpty)
                                    <x-badge value="Needs Coverage" class="badge-warning badge-sm ml-1" />
                                @endif
                            </div>

                            {{-- Assigned Users --}}
                            <div class="flex-1 min-w-0">
                                @if($shift->assignments->isNotEmpty())
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($shift->assignments as $assignment)
                                            <div class="flex items-center gap-1">
                                                <span class="text-sm">
                                                    {{ $assignment->user->first_name }} {{ $assignment->user->last_name }}
                                                    @if($assignment->user->call_sign)
                                                        <span class="text-xs text-base-content/60">({{ $assignment->user->call_sign }})</span>
                                                    @endif
                                                </span>

                                                @switch($assignment->status)
                                                    @case(\App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                                                        <x-badge value="Checked In" class="badge-success badge-xs" />
                                                        @break
                                                    @case(\App\Models\ShiftAssignment::STATUS_CHECKED_OUT)
                                                        <x-badge value="Checked Out" class="badge-neutral badge-xs" />
                                                        @break
                                                    @case(\App\Models\ShiftAssignment::STATUS_NO_SHOW)
                                                        <x-badge value="No Show" class="badge-error badge-xs" />
                                                        @break
                                                    @default
                                                        @if($assignment->confirmed_by_user_id)
                                                            <x-badge value="Confirmed" class="badge-info badge-xs" />
                                                        @else
                                                            <x-badge value="Scheduled" class="badge-warning badge-xs" />
                                                        @endif
                                                @endswitch

                                                @if(!$loop->last)
                                                    <span class="text-base-content/30 mx-1">|</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-sm text-base-content/40 italic">No one signed up yet</span>
                                @endif
                            </div>

                            {{-- Action Buttons --}}
                            @include('livewire.schedule.partials.shift-action-buttons')
                        </div>
                    @endforeach
                </div>
            @else
                <div class="space-y-6" wire:loading.class="opacity-50">
                    @foreach($shiftsByRole as $group)
                        @php
                            $role = $group['role'];
                            $shifts = $group['shifts'];
                        @endphp

                        <x-card>
                            <x-slot:title>
                                <span class="badge text-white" style="background-color: {{ $role->color ?? '#64748b' }}">
                                    @if($role->icon)
                                        <x-icon :name="$role->icon" class="w-5 h-5" />
                                    @endif
                                    {{ $role->name }}
                                </span>
                            </x-slot:title>

                            @if($role->description)
                                <p class="text-sm text-base-content/60 mb-2">{{ $role->description }}</p>
                            @endif

                            @if($shifts->isEmpty())
                                <p class="text-base-content/40 text-sm italic">No shifts scheduled for this role.</p>
                            @else
                                <div class="space-y-3">
                                    @foreach($shifts as $shift)
                                        @php
                                            $filledCount = $shift->assignments->count();
                                            $isFull = $filledCount >= $shift->capacity;
                                            $isMyShift = in_array($shift->id, $myAssignmentIds);
                                            $myAssignment = $myAssignmentsKeyed->get($shift->id);
                                            $isUrgentlyEmpty = $shift->is_urgently_empty;
                                        @endphp

                                        <div class="flex flex-col md:flex-row md:items-center gap-3 p-3 rounded-lg {{ $isUrgentlyEmpty ? 'bg-warning/5 border border-warning border-l-4' : 'bg-base-200/50 border border-base-300' }}">
                                            {{-- Time Range --}}
                                            @php
                                                $startLocal = toLocalTime($shift->start_time);
                                                $endLocal = toLocalTime($shift->end_time);
                                                $spansDays = $startLocal->format('Y-m-d') !== $endLocal->format('Y-m-d');
                                            @endphp
                                            <div class="flex-shrink-0 min-w-[160px]">
                                                <div class="font-medium text-sm">
                                                    {{ $startLocal->format('M j, ' . timeFormat() . ' T') }}
                                                </div>
                                                <div class="text-xs text-base-content/60">
                                                    to {{ $spansDays ? $endLocal->format('M j, ' . timeFormat() . ' T') : $endLocal->format(timeFormat() . ' T') }}
                                                </div>
                                            </div>

                                            {{-- Capacity Indicator --}}
                                            <div class="flex-shrink-0">
                                                <x-badge
                                                    :value="$filledCount . '/' . $shift->capacity . ' filled'"
                                                    :class="$isFull ? 'badge-error badge-sm' : 'badge-success badge-sm'"
                                                />
                                                @if(!$shift->is_open)
                                                    <x-badge value="Closed" class="badge-neutral badge-sm ml-1" />
                                                @endif
                                                @if($isUrgentlyEmpty)
                                                    <x-badge value="Needs Coverage" class="badge-warning badge-sm ml-1" />
                                                @endif
                                            </div>

                                            {{-- Assigned Users --}}
                                            <div class="flex-1 min-w-0">
                                                @if($shift->assignments->isNotEmpty())
                                                    <div class="flex flex-wrap gap-1">
                                                        @foreach($shift->assignments as $assignment)
                                                            <div class="flex items-center gap-1">
                                                                <span class="text-sm">
                                                                    {{ $assignment->user->first_name }} {{ $assignment->user->last_name }}
                                                                    @if($assignment->user->call_sign)
                                                                        <span class="text-xs text-base-content/60">({{ $assignment->user->call_sign }})</span>
                                                                    @endif
                                                                </span>

                                                                {{-- Status Badge --}}
                                                                @switch($assignment->status)
                                                                    @case(\App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                                                                        <x-badge value="Checked In" class="badge-success badge-xs" />
                                                                        @break
                                                                    @case(\App\Models\ShiftAssignment::STATUS_CHECKED_OUT)
                                                                        <x-badge value="Checked Out" class="badge-neutral badge-xs" />
                                                                        @break
                                                                    @case(\App\Models\ShiftAssignment::STATUS_NO_SHOW)
                                                                        <x-badge value="No Show" class="badge-error badge-xs" />
                                                                        @break
                                                                    @default
                                                                        @if($assignment->confirmed_by_user_id)
                                                                            <x-badge value="Confirmed" class="badge-info badge-xs" />
                                                                        @else
                                                                            <x-badge value="Scheduled" class="badge-warning badge-xs" />
                                                                        @endif
                                                                @endswitch

                                                                @if(!$loop->last)
                                                                    <span class="text-base-content/30 mx-1">|</span>
                                                                @endif
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <span class="text-sm text-base-content/40 italic">No one signed up yet</span>
                                                @endif
                                            </div>

                                            {{-- Action Buttons --}}
                                            @include('livewire.schedule.partials.shift-action-buttons')
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </x-card>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
