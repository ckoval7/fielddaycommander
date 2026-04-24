<div class="space-y-6">
    {{-- Header --}}
    <x-header
        title="{{ $stationId ? 'Edit Station' : 'Create Station' }}"
        subtitle="{{ $stationId ? 'Update station configuration and equipment' : 'Configure a new station for field day operations' }}"
        separator
        progress-indicator
    >
        <x-slot:actions>
            <x-button
                label="{{ $stationId ? 'Back to Stations' : 'Cancel' }}"
                icon="{{ $stationId ? 'phosphor-arrow-left' : 'phosphor-x' }}"
                class="btn-ghost"
                link="{{ route('stations.index') }}"
                wire:navigate
            />
        </x-slot:actions>
    </x-header>

    @if($stationId)
        {{-- Tabbed Interface for Editing --}}
        <x-tabs wire:model="activeTab">
            {{-- Configuration Tab --}}
            <x-tab name="configuration" label="Configuration" icon="phosphor-gear-six">
                <form wire:submit="save" class="space-y-6 mt-6">
                    @include('livewire.stations.partials.configuration-form')

                    {{-- Form Actions --}}
                    <div class="flex gap-3">
                        <x-button
                            label="Back to Stations"
                            icon="phosphor-arrow-left"
                            class="btn-ghost"
                            link="{{ route('stations.index') }}"
                            wire:navigate
                        />
                        <x-button
                            label="Update Station"
                            type="submit"
                            class="btn-primary"
                            icon="phosphor-check"
                            spinner="save"
                        />
                    </div>
                </form>
            </x-tab>

            {{-- Equipment Tab --}}
            <x-tab name="equipment" label="Equipment" icon="phosphor-wrench">
                <div class="mt-6">
                    <livewire:stations.equipment-assignment
                        :station-id="$stationId"
                        :key="'equipment-assignment-'.$stationId"
                    />
                </div>
            </x-tab>

            {{-- Activity Tab (Future) --}}
            <x-tab name="activity" label="Activity" icon="phosphor-chart-bar">
                <div class="mt-6">
                    <x-card>
                        <div class="text-center py-12 text-base-content/60">
                            <x-icon name="phosphor-chart-bar" class="w-16 h-16 mx-auto mb-4 opacity-50" />
                            <p class="text-lg font-semibold mb-2">Operating Sessions & Contacts</p>
                            <p class="text-sm">Activity tracking coming soon...</p>
                        </div>
                    </x-card>
                </div>
            </x-tab>
        </x-tabs>
    @else
        {{-- Simple Form for Creation --}}
        <form wire:submit="save" class="space-y-6">
            @include('livewire.stations.partials.configuration-form')

            {{-- Form Actions --}}
            <div class="flex gap-3">
                <x-button
                    label="Cancel"
                    icon="phosphor-x"
                    class="btn-ghost"
                    link="{{ route('stations.index') }}"
                    wire:navigate
                />
                <x-button
                    label="Create Station"
                    type="submit"
                    class="btn-primary"
                    icon="phosphor-check"
                    spinner="save"
                />
            </div>
        </form>
    @endif
</div>
