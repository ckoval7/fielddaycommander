<div>
    <x-slot:title>My Shifts{{ $event ? ' - ' . $event->name : '' }}</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold">My Shifts</h1>
                @if($event)
                    <p class="text-base-content/60">{{ $event->name }}</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <x-button
                    label="Full Shift Schedule"
                    icon="o-calendar"
                    class="btn-outline"
                    link="{{ route('schedule.index') }}"
                />
            </div>
        </div>

        @if($eventConfig)
            @include('livewire.schedule.partials.filter-bar', [
                'showSearch' => false,
                'showTimeFilter' => false,
                'showStatusFilter' => true,
                'showAvailability' => false,
                'statuses' => $this->getFilterStatuses(),
            ])
        @endif

        @if(!$eventConfig)
            <x-alert icon="o-information-circle" class="alert-info">
                No event is currently selected. Please select an event to view your shifts.
            </x-alert>
        @else
            @php
                $currentShifts = $this->currentShifts;
                $upcomingShifts = $this->upcomingShifts;
                $pastShifts = $this->pastShifts;
            @endphp

            <div class="space-y-8">
                {{-- Current Shifts --}}
                <section>
                    <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">
                        <x-icon name="o-signal" class="w-5 h-5 text-success" />
                        Current Shifts
                    </h2>

                    @if($currentShifts->isEmpty())
                        <x-card>
                            <div class="text-center py-6">
                                <x-icon name="o-clock" class="w-12 h-12 mx-auto mb-3 text-base-content/30" />
                                <p class="text-base-content/60">
                                    @if($this->activeFilterCount > 0)
                                        No current shifts match your filters.
                                    @else
                                        You have no shifts happening right now.
                                    @endif
                                </p>
                            </div>
                        </x-card>
                    @else
                        <div class="space-y-3">
                            @foreach($currentShifts as $assignment)
                                @php $shift = $assignment->shift; $role = $shift->shiftRole; @endphp
                                <x-card>
                                    <div class="flex flex-col md:flex-row md:items-center gap-4">
                                        {{-- Role Info --}}
                                        <div class="flex items-center gap-2 flex-1">
                                            @if($role->icon)
                                                <x-icon :name="$role->icon" class="w-5 h-5" />
                                            @endif
                                            <span class="font-semibold">{{ $role->name }}</span>
                                            @if($role->color)
                                                <span class="badge badge-sm text-white" style="background-color: {{ $role->color }}">{{ $role->name }}</span>
                                            @endif
                                        </div>

                                        {{-- Time Range --}}
                                        <div class="text-sm text-base-content/70">
                                            {{ toLocalTime($shift->start_time)->format('g:i A') }} - {{ toLocalTime($shift->end_time)->format('g:i A T') }}
                                        </div>

                                        {{-- Status & Confirmation --}}
                                        <div class="flex items-center gap-2">
                                            @switch($assignment->status)
                                                @case(\App\Models\ShiftAssignment::STATUS_SCHEDULED)
                                                    <x-badge value="Scheduled" class="badge-warning badge-sm" />
                                                    @break
                                                @case(\App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                                                    <x-badge value="Checked In" class="badge-success badge-sm" />
                                                    @break
                                                @case(\App\Models\ShiftAssignment::STATUS_CHECKED_OUT)
                                                    <x-badge value="Checked Out" class="badge-neutral badge-sm" />
                                                    @break
                                                @case(\App\Models\ShiftAssignment::STATUS_NO_SHOW)
                                                    <x-badge value="No Show" class="badge-error badge-sm" />
                                                    @break
                                            @endswitch

                                            @if($role->requires_confirmation)
                                                @if($assignment->confirmed_by_user_id)
                                                    <x-badge value="Confirmed" class="badge-info badge-sm" />
                                                @else
                                                    <x-badge value="Pending Confirmation" class="badge-warning badge-outline badge-sm" />
                                                @endif
                                            @endif
                                        </div>

                                        {{-- Actions --}}
                                        <div class="flex gap-2">
                                            @if($assignment->status === \App\Models\ShiftAssignment::STATUS_SCHEDULED)
                                                @if($shift->can_check_in)
                                                    <x-button
                                                        label="Check In"
                                                        icon="o-arrow-right-on-rectangle"
                                                        class="btn-primary btn-sm"
                                                        wire:click="checkIn({{ $assignment->id }})"
                                                        spinner="checkIn"
                                                    />
                                                @else
                                                    <x-badge value="Check-in opens {{ toLocalTime($shift->start_time->copy()->subMinutes(15))->format('g:i A T') }}" class="badge-ghost badge-sm" />
                                                @endif
                                            @elseif($assignment->status === \App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                                                @php $minutesLeft = (int) appNow()->diffInMinutes($shift->end_time); @endphp
                                                <x-button
                                                    label="Check Out"
                                                    icon="o-arrow-left-on-rectangle"
                                                    class="btn-warning btn-sm"
                                                    wire:click="checkOut({{ $assignment->id }})"
                                                    wire:confirm="You still have {{ $minutesLeft }} {{ $minutesLeft === 1 ? 'minute' : 'minutes' }} left in this shift. Are you sure you want to check out?"
                                                    spinner="checkOut"
                                                />
                                            @elseif($assignment->status === \App\Models\ShiftAssignment::STATUS_CHECKED_OUT)
                                                <x-button
                                                    label="Check In Again"
                                                    icon="o-arrow-right-on-rectangle"
                                                    class="btn-ghost btn-sm"
                                                    wire:click="reCheckIn({{ $assignment->id }})"
                                                    spinner="reCheckIn"
                                                />
                                            @endif
                                        </div>
                                    </div>
                                </x-card>
                            @endforeach
                        </div>
                    @endif
                </section>

                {{-- Upcoming Shifts --}}
                <section>
                    <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">
                        <x-icon name="o-arrow-right" class="w-5 h-5 text-info" />
                        Upcoming Shifts
                    </h2>

                    @if($upcomingShifts->isEmpty())
                        <x-card>
                            <div class="text-center py-6">
                                <x-icon name="o-calendar" class="w-12 h-12 mx-auto mb-3 text-base-content/30" />
                                <p class="text-base-content/60">
                                    @if($this->activeFilterCount > 0)
                                        No upcoming shifts match your filters.
                                    @else
                                        You have no upcoming shifts scheduled.
                                    @endif
                                </p>
                            </div>
                        </x-card>
                    @else
                        <div class="space-y-3">
                            @foreach($upcomingShifts as $assignment)
                                @php $shift = $assignment->shift; $role = $shift->shiftRole; @endphp
                                <x-card>
                                    <div class="flex flex-col md:flex-row md:items-center gap-4">
                                        {{-- Role Info --}}
                                        <div class="flex items-center gap-2 flex-1">
                                            @if($role->icon)
                                                <x-icon :name="$role->icon" class="w-5 h-5" />
                                            @endif
                                            <span class="font-semibold">{{ $role->name }}</span>
                                            @if($role->color)
                                                <span class="badge badge-sm text-white" style="background-color: {{ $role->color }}">{{ $role->name }}</span>
                                            @endif
                                        </div>

                                        {{-- Time Range --}}
                                        <div class="text-sm text-base-content/70">
                                            {{ toLocalTime($shift->start_time)->format('M j, g:i A') }} - {{ toLocalTime($shift->end_time)->format('g:i A T') }}
                                        </div>

                                        {{-- Actions --}}
                                        <div class="flex gap-2">
                                            @if($assignment->signup_type === \App\Models\ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP && $assignment->status === \App\Models\ShiftAssignment::STATUS_SCHEDULED)
                                                <x-button
                                                    label="Drop"
                                                    icon="o-x-mark"
                                                    class="btn-ghost btn-sm text-error"
                                                    wire:click="cancelSignUp({{ $assignment->id }})"
                                                    wire:confirm="Are you sure you want to drop this shift?"
                                                    spinner="cancelSignUp"
                                                />
                                            @endif
                                        </div>
                                    </div>
                                </x-card>
                            @endforeach
                        </div>
                    @endif
                </section>

                {{-- Past Shifts --}}
                <section>
                    <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">
                        <x-icon name="o-clock" class="w-5 h-5 text-base-content/50" />
                        Past Shifts
                    </h2>

                    @if($pastShifts->isEmpty())
                        <x-card>
                            <div class="text-center py-6">
                                <x-icon name="o-archive-box" class="w-12 h-12 mx-auto mb-3 text-base-content/30" />
                                <p class="text-base-content/60">
                                    @if($this->activeFilterCount > 0)
                                        No past shifts match your filters.
                                    @else
                                        You have no past shifts for this event.
                                    @endif
                                </p>
                            </div>
                        </x-card>
                    @else
                        <div class="space-y-3">
                            @foreach($pastShifts as $assignment)
                                @php $shift = $assignment->shift; $role = $shift->shiftRole; @endphp
                                <x-card>
                                    <div class="flex flex-col md:flex-row md:items-center gap-4">
                                        {{-- Role Info --}}
                                        <div class="flex items-center gap-2 flex-1">
                                            @if($role->icon)
                                                <x-icon :name="$role->icon" class="w-5 h-5" />
                                            @endif
                                            <span class="font-semibold">{{ $role->name }}</span>
                                            @if($role->color)
                                                <span class="badge badge-sm text-white" style="background-color: {{ $role->color }}">{{ $role->name }}</span>
                                            @endif
                                        </div>

                                        {{-- Time Range --}}
                                        <div class="text-sm text-base-content/70">
                                            {{ toLocalTime($shift->start_time)->format('M j, g:i A') }} - {{ toLocalTime($shift->end_time)->format('g:i A T') }}
                                        </div>

                                        {{-- Status --}}
                                        <div class="flex items-center gap-2">
                                            @switch($assignment->status)
                                                @case(\App\Models\ShiftAssignment::STATUS_CHECKED_OUT)
                                                    <x-badge value="Checked Out" class="badge-neutral badge-sm" />
                                                    @break
                                                @case(\App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                                                    <x-badge value="Checked In" class="badge-success badge-sm" />
                                                    @break
                                                @case(\App\Models\ShiftAssignment::STATUS_NO_SHOW)
                                                    <x-badge value="No Show" class="badge-error badge-sm" />
                                                    @break
                                                @default
                                                    <x-badge value="Scheduled" class="badge-warning badge-sm" />
                                            @endswitch

                                            @if($role->requires_confirmation)
                                                @if($assignment->confirmed_by_user_id)
                                                    <x-badge value="Confirmed" class="badge-info badge-sm" />
                                                @else
                                                    <x-badge value="Pending Confirmation" class="badge-warning badge-outline badge-sm" />
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                </x-card>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>
        @endif
    </div>
</div>
