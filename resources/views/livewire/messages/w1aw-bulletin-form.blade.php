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
                            @can('manage-bulletins')
                                <th class="text-right">Actions</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->scheduleEntries as $entry)
                            <tr class="{{ $entry->scheduled_at->isPast() ? 'opacity-40' : '' }} {{ $this->nextScheduleEntry && $entry->id === $this->nextScheduleEntry->id ? 'bg-info/5 font-medium' : '' }}">
                                <td>
                                    {{ $entry->scheduled_at->format('D M j, H:i') }}
                                    @if($entry->notes)
                                        <div class="text-xs text-base-content/50 mt-0.5">{{ $entry->notes }}</div>
                                    @endif
                                </td>
                                <td>{{ $entry->mode_label }}</td>
                                <td class="font-mono text-sm">{{ $entry->frequencies }}</td>
                                <td>{{ $entry->source }}</td>
                                @can('manage-bulletins')
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
        @can('manage-bulletins')
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
                <x-input
                    label="Notes (optional)"
                    wire:model="scheduleNotes"
                    placeholder="e.g., Listen on USB, signal may be weak"
                    maxlength="500"
                    class="mt-3"
                />
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

            {{-- Edit History --}}
            @if($this->editHistory->isNotEmpty())
                <x-card>
                    <x-slot:title>
                        <div class="flex items-center gap-2">
                            <x-icon name="o-clock" class="w-5 h-5" />
                            Edit History
                        </div>
                    </x-slot:title>

                    <div class="space-y-4">
                        @foreach($this->editHistory as $entry)
                            <div class="border border-base-300 rounded-lg p-3">
                                <div class="flex items-center gap-2 text-sm text-base-content/70 mb-2">
                                    <span class="font-medium">{{ $entry->user?->call_sign ?? $entry->user?->first_name ?? 'System' }}</span>
                                    <span>&middot;</span>
                                    <span>{{ $entry->created_at->diffForHumans() }}</span>
                                    <span class="text-xs text-base-content/50">({{ $entry->created_at->format('M j, Y H:i') }} UTC)</span>
                                </div>

                                @if($entry->action === 'bulletin.created')
                                    <div class="text-sm text-success">Created bulletin</div>
                                @else
                                    {{-- Metadata changes --}}
                                    @foreach(['frequency', 'mode', 'received_at'] as $field)
                                        @if(isset($entry->old_values[$field]) && isset($entry->new_values[$field]) && $entry->old_values[$field] !== $entry->new_values[$field])
                                            <div class="text-sm mb-1">
                                                Changed <span class="font-medium">{{ $field }}</span>
                                                from <code class="text-error">{{ $entry->old_values[$field] }}</code>
                                                to <code class="text-success">{{ $entry->new_values[$field] }}</code>
                                            </div>
                                        @endif
                                    @endforeach

                                    {{-- Bulletin text diff --}}
                                    @if(isset($entry->old_values['bulletin_text']) && isset($entry->new_values['bulletin_text']))
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-sm font-medium text-base-content/70 hover:text-base-content">
                                                Bulletin text changed
                                            </summary>
                                            <div class="mt-2 font-mono text-xs leading-relaxed overflow-x-auto">
                                                @foreach($this->diffLines($entry->old_values['bulletin_text'], $entry->new_values['bulletin_text']) as $line)
                                                    <div class="{{ match($line['type']) {
                                                        'added' => 'bg-success/10 text-success border-l-2 border-success pl-2',
                                                        'removed' => 'bg-error/10 text-error border-l-2 border-error pl-2',
                                                        default => 'pl-2 text-base-content/60',
                                                    } }}">{{ $line['type'] === 'added' ? '+ ' : ($line['type'] === 'removed' ? '- ' : '  ') }}{{ $line['text'] }}</div>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

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

            const days = Math.floor(diff / 86400000);
            const hours = Math.floor((diff % 86400000) / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);

            if (days > 0) {
                this.countdown = `${days}d ${hours}h ${minutes}m`;
            } else if (hours > 0) {
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
