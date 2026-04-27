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

            {{-- Activity Tab --}}
            <x-tab name="activity" label="Activity" icon="phosphor-chart-bar">
                <div class="mt-6 space-y-6">
                    {{-- Summary stats --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-card>
                            <div class="text-sm text-base-content/60">Total QSOs</div>
                            <div class="text-3xl font-bold">{{ $this->totalQsoCount }}</div>
                        </x-card>
                        <x-card>
                            <div class="text-sm text-base-content/60">Total Sessions</div>
                            <div class="text-3xl font-bold">{{ $this->sessions->count() }}</div>
                        </x-card>
                    </div>

                    {{-- Sessions table --}}
                    <x-card>
                        <h3 class="card-title">Operating Sessions</h3>

                        @if($this->sessions->isEmpty())
                            <x-alert icon="phosphor-info">
                                No operating sessions logged for this station yet.
                            </x-alert>
                        @else
                            <div class="overflow-x-auto">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Operator</th>
                                            <th>Band</th>
                                            <th>Mode</th>
                                            <th>Start</th>
                                            <th>End</th>
                                            <th>Duration</th>
                                            <th>QSOs</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($this->sessions as $session)
                                            <tr>
                                                <td>{{ $session->operator->call_sign ?? '—' }}</td>
                                                <td>{{ $session->band->name ?? '—' }}</td>
                                                <td>{{ $session->mode->name ?? '—' }}</td>
                                                <td>{{ $session->start_time ? toLocalTime($session->start_time)->format('M j, g:i A T') : '' }}</td>
                                                <td>
                                                    @if($session->end_time)
                                                        {{ toLocalTime($session->end_time)->format('M j, g:i A T') }}
                                                    @else
                                                        <x-badge value="Active" class="badge-success" />
                                                    @endif
                                                </td>
                                                <td>{{ $session->duration ?? '—' }}</td>
                                                <td>{{ $session->qso_count ?? 0 }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
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
