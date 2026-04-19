{{-- Variables: $shift, $myAssignment, $isMyShift, $isFull --}}
<div class="flex-shrink-0 flex gap-1">
    @if($isMyShift && $myAssignment)
        @switch($myAssignment->status)
            @case(\App\Models\ShiftAssignment::STATUS_SCHEDULED)
                @if($shift->can_check_in)
                    <x-button
                        label="Check In"
                        icon="phosphor-sign-in"
                        class="btn-primary btn-sm"
                        wire:click="checkIn({{ $myAssignment->id }})"
                        spinner="checkIn"
                    />
                @else
                    <x-badge value="Check-in opens {{ toLocalTime($shift->start_time->copy()->subMinutes(15))->format(timeFormat() . ' T') }}" class="badge-ghost badge-sm" />
                @endif
                @if($myAssignment->signup_type === \App\Models\ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP)
                    <x-button
                        label="Drop"
                        icon="phosphor-x"
                        class="btn-ghost btn-sm text-error"
                        wire:click="cancelSignUp({{ $myAssignment->id }})"
                        wire:confirm="Are you sure you want to drop this shift?"
                        spinner="cancelSignUp"
                    />
                @endif
                @break
            @case(\App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                @php
                    $minutesLeft = (int) appNow()->diffInMinutes($shift->end_time);
                    $checkoutConfirm = $minutesLeft >= 1
                        ? "You still have {$minutesLeft} " . ($minutesLeft === 1 ? 'minute' : 'minutes') . ' left in this shift. Are you sure you want to check out?'
                        : null;
                @endphp
                <x-button
                    label="Check Out"
                    icon="phosphor-sign-out"
                    class="btn-warning btn-sm"
                    wire:click="checkOut({{ $myAssignment->id }})"
                    :wire:confirm="$checkoutConfirm"
                    spinner="checkOut"
                />
                @break
            @case(\App\Models\ShiftAssignment::STATUS_CHECKED_OUT)
                <x-button
                    label="Check In Again"
                    icon="phosphor-sign-in"
                    class="btn-ghost btn-sm"
                    wire:click="reCheckIn({{ $myAssignment->id }})"
                    spinner="reCheckIn"
                />
                @break
        @endswitch
    @elseif($shift->is_open && !$isFull && !$isMyShift)
        <x-button
            label="Sign Up"
            icon="phosphor-plus"
            class="btn-success btn-sm"
            wire:click="signUp({{ $shift->id }})"
            spinner="signUp"
            :disabled="$shift->is_past"
        />
    @endif
</div>
