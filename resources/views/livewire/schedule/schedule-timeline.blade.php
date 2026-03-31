<div>
    <x-slot:title>Schedule{{ $event ? ' - ' . $event->name : '' }}</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold">Schedule</h1>
                @if($event)
                    <p class="text-base-content/60">{{ $event->name }}</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <x-button
                    label="My Shifts"
                    icon="o-calendar-days"
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
            <x-alert icon="o-information-circle" class="alert-info">
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
                        <x-icon name="o-funnel" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
                        <h3 class="text-lg font-semibold mb-2">No shifts match your filters</h3>
                        <p class="text-base-content/60 mb-4">Try adjusting your filters to see more shifts.</p>
                        <x-button
                            label="Clear Filters"
                            icon="o-x-mark"
                            class="btn-outline btn-sm"
                            wire:click="resetFilters"
                        />
                    </div>
                @else
                    <div class="text-center py-12">
                        <x-icon name="o-calendar" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
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
                        @endphp

                        <div class="flex flex-col md:flex-row md:items-center gap-3 p-3 rounded-lg bg-base-200/50 border border-base-300">
                            {{-- Role Badge --}}
                            <div class="flex-shrink-0">
                                <span class="badge badge-outline badge-sm gap-1">
                                    @if($shift->shiftRole?->icon)
                                        <x-icon :name="$shift->shiftRole->icon" class="w-3 h-3" />
                                    @endif
                                    {{ $shift->shiftRole?->name }}
                                </span>
                            </div>

                            {{-- Time Range --}}
                            <div class="flex-shrink-0 min-w-[160px]">
                                <div class="font-medium text-sm">
                                    {{ toLocalTime($shift->start_time)->format('M j, g:i A T') }}
                                </div>
                                <div class="text-xs text-base-content/60">
                                    to {{ toLocalTime($shift->end_time)->format('g:i A T') }}
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
                            <div class="flex-shrink-0 flex gap-1">
                                @if($isMyShift && $myAssignment)
                                    @switch($myAssignment->status)
                                        @case(\App\Models\ShiftAssignment::STATUS_SCHEDULED)
                                            @if($shift->can_check_in)
                                                <x-button
                                                    label="Check In"
                                                    icon="o-arrow-right-on-rectangle"
                                                    class="btn-primary btn-sm"
                                                    wire:click="checkIn({{ $myAssignment->id }})"
                                                    spinner="checkIn"
                                                />
                                            @else
                                                <x-badge value="Check-in opens {{ toLocalTime($shift->start_time->copy()->subMinutes(15))->format('g:i A T') }}" class="badge-ghost badge-sm" />
                                            @endif
                                            @if($myAssignment->signup_type === \App\Models\ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP)
                                                <x-button
                                                    label="Cancel"
                                                    icon="o-x-mark"
                                                    class="btn-ghost btn-sm text-error"
                                                    wire:click="cancelSignUp({{ $myAssignment->id }})"
                                                    wire:confirm="Are you sure you want to cancel this sign-up?"
                                                    spinner="cancelSignUp"
                                                />
                                            @endif
                                            @break
                                        @case(\App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                                            <x-button
                                                label="Check Out"
                                                icon="o-arrow-left-on-rectangle"
                                                class="btn-warning btn-sm"
                                                wire:click="checkOut({{ $myAssignment->id }})"
                                                spinner="checkOut"
                                            />
                                            @break
                                    @endswitch
                                @elseif($shift->is_open && !$isFull && !$isMyShift)
                                    <x-button
                                        label="Sign Up"
                                        icon="o-plus"
                                        class="btn-success btn-sm"
                                        wire:click="signUp({{ $shift->id }})"
                                        spinner="signUp"
                                    />
                                @endif
                            </div>
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
                                <div class="flex items-center gap-2">
                                    @if($role->icon)
                                        <x-icon :name="$role->icon" class="w-5 h-5" />
                                    @endif
                                    <span>{{ $role->name }}</span>
                                </div>
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
                                        @endphp

                                        <div class="flex flex-col md:flex-row md:items-center gap-3 p-3 rounded-lg bg-base-200/50 border border-base-300">
                                            {{-- Time Range --}}
                                            <div class="flex-shrink-0 min-w-[160px]">
                                                <div class="font-medium text-sm">
                                                    {{ toLocalTime($shift->start_time)->format('M j, g:i A T') }}
                                                </div>
                                                <div class="text-xs text-base-content/60">
                                                    to {{ toLocalTime($shift->end_time)->format('g:i A T') }}
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
                                            <div class="flex-shrink-0 flex gap-1">
                                                @if($isMyShift && $myAssignment)
                                                    @switch($myAssignment->status)
                                                        @case(\App\Models\ShiftAssignment::STATUS_SCHEDULED)
                                                            @if($shift->can_check_in)
                                                                <x-button
                                                                    label="Check In"
                                                                    icon="o-arrow-right-on-rectangle"
                                                                    class="btn-primary btn-sm"
                                                                    wire:click="checkIn({{ $myAssignment->id }})"
                                                                    spinner="checkIn"
                                                                />
                                                            @else
                                                                <x-badge value="Check-in opens {{ toLocalTime($shift->start_time->copy()->subMinutes(15))->format('g:i A T') }}" class="badge-ghost badge-sm" />
                                                            @endif
                                                            @if($myAssignment->signup_type === \App\Models\ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP)
                                                                <x-button
                                                                    label="Cancel"
                                                                    icon="o-x-mark"
                                                                    class="btn-ghost btn-sm text-error"
                                                                    wire:click="cancelSignUp({{ $myAssignment->id }})"
                                                                    wire:confirm="Are you sure you want to cancel this sign-up?"
                                                                    spinner="cancelSignUp"
                                                                />
                                                            @endif
                                                            @break
                                                        @case(\App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                                                            <x-button
                                                                label="Check Out"
                                                                icon="o-arrow-left-on-rectangle"
                                                                class="btn-warning btn-sm"
                                                                wire:click="checkOut({{ $myAssignment->id }})"
                                                                spinner="checkOut"
                                                            />
                                                            @break
                                                    @endswitch
                                                @elseif($shift->is_open && !$isFull && !$isMyShift)
                                                    <x-button
                                                        label="Sign Up"
                                                        icon="o-plus"
                                                        class="btn-success btn-sm"
                                                        wire:click="signUp({{ $shift->id }})"
                                                        spinner="signUp"
                                                    />
                                                @endif
                                            </div>
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
