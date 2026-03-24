<div class="space-y-6">
    {{-- Header --}}
    <x-header
        title="W1AW Field Day Bulletin"
        subtitle="Capture the W1AW bulletin received during Field Day"
        separator
        progress-indicator
    >
        <x-slot:actions>
            @if(\Illuminate\Support\Facades\Route::has('events.messages.index'))
                <x-button
                    label="Back to Messages"
                    icon="o-arrow-left"
                    class="btn-ghost"
                    link="{{ route('events.messages.index', $event) }}"
                    wire:navigate
                />
            @endif
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
                    label="Received At"
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
</div>
