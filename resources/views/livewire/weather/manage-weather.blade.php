<div class="p-4 md:p-6 max-w-3xl mx-auto space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Manage Weather</h1>
        <a href="{{ route('weather.index') }}" wire:navigate class="btn btn-ghost btn-sm">
            <x-icon name="o-arrow-left" class="w-4 h-4" />
            Back to Forecast
        </a>
    </div>

    {{-- Manual Forecast Override --}}
    <div class="card bg-base-200 shadow">
        <div class="card-body space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="card-title text-base">Manual Forecast Override</h2>
                @if($overrideActive)
                    <div class="badge badge-info">Active</div>
                @endif
            </div>

            @if($overrideActive && $currentOverride)
                <div class="p-3 bg-info/10 rounded text-sm space-y-1">
                    <p><span class="font-medium">Currently active:</span>
                        {{ $currentOverride['temperature'] ?? '—' }}°F ·
                        Wind {{ $currentOverride['wind_speed'] ?? '—' }} mph {{ $currentOverride['wind_direction'] ?? '' }} ·
                        {{ $currentOverride['precipitation_chance'] ?? '—' }}% rain chance
                    </p>
                    @if(!empty($currentOverride['notes']))
                        <p class="text-base-content/70">{{ $currentOverride['notes'] }}</p>
                    @endif
                    <p class="text-xs text-base-content/50">
                        Set by {{ $currentOverride['updated_by'] ?? '?' }}
                    </p>
                </div>
                <button wire:click="clearOverride" wire:confirm="Clear the manual override and return to live data?"
                    class="btn btn-outline btn-sm">
                    Clear Override
                </button>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="label label-text text-xs">Temp (°F)</label>
                        <input wire:model="temperature" type="number" class="input input-bordered input-sm w-full" placeholder="72" />
                        @error('temperature') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label label-text text-xs">Wind (mph)</label>
                        <input wire:model="windSpeed" type="number" class="input input-bordered input-sm w-full" placeholder="10" />
                        @error('windSpeed') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label label-text text-xs">Direction</label>
                        <select wire:model="windDirection" class="select select-bordered select-sm w-full">
                            @foreach(['N','NE','E','SE','S','SW','W','NW'] as $dir)
                                <option value="{{ $dir }}">{{ $dir }}</option>
                            @endforeach
                        </select>
                        @error('windDirection') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label label-text text-xs">Rain %</label>
                        <input wire:model="precipitationChance" type="number" min="0" max="100" class="input input-bordered input-sm w-full" placeholder="20" />
                        @error('precipitationChance') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label label-text text-xs">Notes (optional)</label>
                    <textarea wire:model="notes" class="textarea textarea-bordered w-full text-sm" rows="2"
                        placeholder="Radar showing cells to the west, monitor closely"></textarea>
                    @error('notes') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <button wire:click="activateOverride" class="btn btn-primary btn-sm">
                    Activate Override
                </button>
            @endif
        </div>
    </div>

    {{-- Manual Alert Controls --}}
    <div class="card bg-base-200 shadow">
        <div class="card-body space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="card-title text-base">Storm / Lightning Alert</h2>
                @if(!empty($currentAlerts))
                    <div class="badge badge-warning">Alert Active</div>
                @endif
            </div>

            @if(!empty($currentAlerts))
                <div class="alert alert-warning text-sm">
                    <x-icon name="o-exclamation-triangle" class="w-5 h-5 shrink-0" />
                    <div>
                        @foreach($currentAlerts as $alert)
                            <p>{{ $alert['headline'] }}</p>
                        @endforeach
                    </div>
                </div>
                <button wire:click="clearAlert" wire:confirm="Clear the active alert for all users?"
                    class="btn btn-outline btn-sm">
                    Clear Alert
                </button>
            @else
                <div class="space-y-2">
                    <label class="label label-text text-xs">Alert Message</label>
                    <input wire:model="alertMessage" type="text" class="input input-bordered w-full"
                        placeholder="Lightning within 10 miles — seek shelter immediately" />
                    @error('alertMessage') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <button wire:click="triggerAlert" class="btn btn-warning btn-sm">
                    Trigger Alert
                </button>
            @endif

            <p class="text-xs text-base-content/50">
                Triggering an alert broadcasts immediately to all connected users via the alert banner.
                NWS alerts are automatic and will override this when available.
            </p>
        </div>
    </div>

</div>
