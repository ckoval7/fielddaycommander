<div class="space-y-6">
    {{-- Header --}}
    <x-header
        title="W1AW Field Day Bulletin"
        subtitle="Capture the W1AW bulletin received during Field Day"
        separator
        progress-indicator
    >
        <x-slot:actions>
            <x-button
                label="Back to Dashboard"
                icon="o-arrow-left"
                class="btn-ghost"
                link="/"
                wire:navigate
            />
        </x-slot:actions>
    </x-header>

    {{-- Status Badge --}}
    <div>
        @if($bulletinId)
            <x-badge value="Bonus Earned: 100 pts" class="badge-success" icon="o-check-circle" />
        @else
            <x-badge value="Not Yet Captured" class="badge-warning" icon="o-clock" />
        @endif
    </div>

    {{-- Transmission Schedule --}}
    <x-card>
        <x-slot:title>
            <div class="flex items-center gap-2">
                <x-icon name="o-radio" class="w-5 h-5" />
                Transmission Schedule
            </div>
        </x-slot:title>

        @if($this->scheduleEntries->isNotEmpty())
            {{-- Next transmission countdown --}}
            @if($this->nextScheduleEntry)
                <div
                    class="mb-4 p-3 bg-info/10 border border-info/20 rounded-lg"
                    x-data="bulletinCountdown('{{ $this->nextScheduleEntry->scheduled_at->toIso8601String() }}')"
                >
                    <div class="flex items-center gap-2 text-info">
                        <x-icon name="o-clock" class="w-4 h-4" />
                        <span class="text-sm font-medium">
                            Next: {{ $this->nextScheduleEntry->mode_label }} on {{ $this->nextScheduleEntry->frequencies }} MHz
                            — <span x-text="countdown">loading...</span>
                        </span>
                    </div>
                </div>
            @endif

            {{-- Schedule table --}}
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Time (UTC)</th>
                            <th>Mode</th>
                            <th>Frequencies (MHz)</th>
                            <th>Source</th>
                            @can('manage-event-config')
                                <th class="text-right">Actions</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->scheduleEntries as $entry)
                            <tr class="{{ $entry->scheduled_at->isPast() ? 'opacity-40' : '' }} {{ $this->nextScheduleEntry && $entry->id === $this->nextScheduleEntry->id ? 'bg-info/5 font-medium' : '' }}">
                                <td>{{ $entry->scheduled_at->format('D M j, H:i') }}</td>
                                <td>{{ $entry->mode_label }}</td>
                                <td class="font-mono text-sm">{{ $entry->frequencies }}</td>
                                <td>{{ $entry->source }}</td>
                                @can('manage-event-config')
                                    <td class="text-right">
                                        <div class="flex gap-1 justify-end">
                                            <x-button
                                                icon="o-pencil"
                                                class="btn-ghost btn-xs"
                                                wire:click="editScheduleEntry({{ $entry->id }})"
                                                spinner="editScheduleEntry({{ $entry->id }})"
                                                title="Edit"
                                            />
                                            <x-button
                                                icon="o-trash"
                                                class="btn-ghost btn-xs text-error"
                                                wire:click="deleteScheduleEntry({{ $entry->id }})"
                                                wire:confirm="Remove this transmission from the schedule?"
                                                spinner="deleteScheduleEntry({{ $entry->id }})"
                                                title="Delete"
                                            />
                                        </div>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-base-content/50">No transmissions scheduled yet.</p>
        @endif

        {{-- Add/Edit form (managers only) --}}
        @can('manage-event-config')
            <div class="mt-4 pt-4 border-t border-base-300">
                <h4 class="text-sm font-semibold mb-3">
                    {{ $editingEntryId ? 'Edit Transmission' : 'Add Transmission' }}
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                    <x-flatpickr
                        label="Time (UTC)"
                        wire:model="scheduleScheduledAt"
                        icon="o-clock"
                    />
                    <x-select
                        label="Mode"
                        wire:model="scheduleMode"
                        :options="[
                            ['id' => 'cw', 'name' => 'CW'],
                            ['id' => 'digital', 'name' => 'Digital'],
                            ['id' => 'phone', 'name' => 'Phone'],
                        ]"
                        option-value="id"
                        option-label="name"
                        placeholder="Select mode"
                    />
                    <x-input
                        label="Frequencies (MHz)"
                        wire:model="scheduleFrequencies"
                        placeholder="e.g., 7.0475, 14.0475"
                    />
                    <x-select
                        label="Source"
                        wire:model="scheduleSource"
                        :options="[
                            ['id' => 'W1AW', 'name' => 'W1AW'],
                            ['id' => 'K6KPH', 'name' => 'K6KPH'],
                        ]"
                        option-value="id"
                        option-label="name"
                    />
                </div>
                <div class="flex gap-2 mt-3">
                    @if($editingEntryId)
                        <x-button
                            label="Update"
                            wire:click="updateScheduleEntry"
                            class="btn-primary btn-sm"
                            icon="o-check"
                            spinner="updateScheduleEntry"
                        />
                        <x-button
                            label="Cancel"
                            wire:click="cancelEditScheduleEntry"
                            class="btn-ghost btn-sm"
                            spinner="cancelEditScheduleEntry"
                        />
                    @else
                        <x-button
                            label="Add Transmission"
                            wire:click="addScheduleEntry"
                            class="btn-primary btn-sm"
                            icon="o-plus"
                            spinner="addScheduleEntry"
                        />
                    @endif
                </div>
            </div>
        @endcan
    </x-card>

    {{-- My Reminder Settings --}}
    <x-card>
        <x-slot:title>
            <div class="flex items-center gap-2">
                <x-icon name="o-bell" class="w-5 h-5" />
                My Reminder Settings
            </div>
        </x-slot:title>

        @if(count($this->reminderMinutes) > 0)
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach($this->reminderMinutes as $minutes)
                    <span class="badge badge-primary gap-1">
                        {{ $minutes }} min
                        <button
                            wire:click="removeReminderMinute({{ $minutes }})"
                            class="btn btn-ghost btn-xs p-0 min-h-0 h-auto"
                            title="Remove"
                        >
                            <x-icon name="o-x-mark" class="w-3 h-3" />
                        </button>
                    </span>
                @endforeach
            </div>
        @else
            <p class="text-sm text-base-content/50 mb-4">No reminders configured. You won't receive bulletin notifications.</p>
        @endif

        @if(count($this->reminderMinutes) < 5)
            <div class="flex items-end gap-2">
                <x-input
                    label="Minutes before"
                    wire:model="reminderMinute"
                    type="number"
                    min="1"
                    max="60"
                    placeholder="e.g., 15"
                    class="w-32"
                />
                <x-button
                    label="Add"
                    wire:click="addReminderMinute"
                    class="btn-primary btn-sm"
                    icon="o-plus"
                    spinner="addReminderMinute"
                />
            </div>
            @error('reminderMinute')
                <p class="text-error text-xs mt-1">{{ $message }}</p>
            @enderror
        @endif
    </x-card>

    @can('log-contacts')
        <form wire:submit="save" class="space-y-6">

            <x-card>
                <x-slot:title>Bulletin Details</x-slot:title>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {{-- Frequency --}}
                    <x-input
                        label="Frequency (MHz)"
                        wire:model="frequency"
                        icon="o-signal"
                        placeholder="e.g., 14.0475"
                        maxlength="20"
                        required
                    />

                    {{-- Mode --}}
                    <x-select
                        label="Mode"
                        wire:model="mode"
                        :options="[
                            ['id' => 'cw', 'name' => 'CW'],
                            ['id' => 'digital', 'name' => 'Digital'],
                            ['id' => 'phone', 'name' => 'Phone'],
                        ]"
                        option-value="id"
                        option-label="name"
                        icon="o-radio"
                        placeholder="Select mode"
                        required
                    />

                    {{-- Received At --}}
                    <x-flatpickr
                        label="Received At (UTC)"
                        wire:model="receivedAt"
                        icon="o-clock"
                        required
                    />
                </div>
            </x-card>

            {{-- Bulletin Text --}}
            <x-card>
                <x-slot:title>Bulletin Text</x-slot:title>

                <x-textarea
                    label="Bulletin Text"
                    wire:model="bulletinText"
                    placeholder="Enter the W1AW bulletin text as received..."
                    rows="10"
                    required
                    class="font-mono"
                />
            </x-card>

            {{-- Actions --}}
            <div class="flex gap-3">
                <x-button
                    label="{{ $bulletinId ? 'Update Bulletin' : 'Save Bulletin' }}"
                    type="submit"
                    class="btn-primary"
                    icon="o-check"
                    spinner="save"
                />

                @if($bulletinId)
                    <x-button
                        label="Delete Bulletin"
                        wire:click="deleteBulletin"
                        wire:confirm="Are you sure you want to delete this bulletin? This cannot be undone."
                        class="btn-error"
                        icon="o-trash"
                        spinner="deleteBulletin"
                    />
                @endif
            </div>

        </form>
    @endcan
</div>

@script
<script>
    Alpine.data('bulletinCountdown', (isoTime) => ({
        countdown: 'loading...',
        interval: null,
        init() {
            this.tick();
            this.interval = setInterval(() => this.tick(), 1000);
        },
        tick() {
            const target = new Date(isoTime);
            const now = new Date();
            const diff = target - now;

            if (diff <= 0) {
                this.countdown = 'NOW';
                clearInterval(this.interval);
                return;
            }

            const hours = Math.floor(diff / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);

            if (hours > 0) {
                this.countdown = `${hours}h ${minutes}m ${seconds}s`;
            } else if (minutes > 0) {
                this.countdown = `${minutes}m ${seconds}s`;
            } else {
                this.countdown = `${seconds}s`;
            }
        },
        destroy() {
            clearInterval(this.interval);
        }
    }));
</script>
@endscript
