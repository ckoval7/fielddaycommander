<div class="p-4 md:p-6 max-w-3xl mx-auto space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Manage Weather</h1>
        <a href="{{ route('weather.index') }}" wire:navigate class="btn btn-ghost btn-sm">
            <x-icon name="phosphor-arrow-left" class="w-4 h-4" />
            Back to Forecast
        </a>
    </div>

    {{-- Location Warning --}}
    @if($this->locationConfig === null)
        <div class="alert alert-warning text-sm">
            <x-icon name="phosphor-warning-duotone" class="w-5 h-5 shrink-0" />
            <div>
                <p class="font-medium">Weather APIs cannot fetch data</p>
                <p class="text-xs mt-0.5">No active or upcoming event has a location configured. Set latitude, longitude, and state on an event to enable live weather fetching.</p>
            </div>
        </div>
    @endif

    {{-- Unit System --}}
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="card-title text-base">Unit System</h2>
                    <p class="text-xs text-base-content/60 mt-1">Applies to all users. Takes effect after the next weather fetch.</p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button
                        wire:click="saveUnits('imperial')"
                        class="btn btn-sm {{ $units === 'imperial' ? 'btn-primary' : 'btn-outline' }}">
                        Imperial (°F, mph)
                    </button>
                    <button
                        wire:click="saveUnits('metric')"
                        class="btn btn-sm {{ $units === 'metric' ? 'btn-primary' : 'btn-outline' }}">
                        Metric (°C, km/h)
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- API Status --}}
    <div class="card bg-base-200 shadow">
        <div class="card-body space-y-3">
            <h2 class="card-title text-base">API Status</h2>

            {{-- Open-Meteo Forecast --}}
            <div class="collapse collapse-arrow bg-base-100">
                <input type="checkbox" aria-label="Open-Meteo troubleshooting details" />
                <div class="collapse-title p-3 min-h-0 pr-10">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-3 relative z-10">
                            <input type="checkbox" class="toggle toggle-sm toggle-primary"
                                wire:click.stop="toggleOpenMeteo"
                                @checked($openMeteoEnabled)
                                wire:confirm="{{ $openMeteoEnabled ? 'Disable Open-Meteo? The stored forecast will be cleared.' : 'Enable Open-Meteo forecast polling?' }}" />
                            <span class="text-sm font-medium">Open-Meteo Forecast</span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if(! $openMeteoEnabled)
                                <div class="badge badge-ghost badge-sm">Disabled</div>
                            @elseif($forecastStatus === null)
                                <div class="badge badge-ghost badge-sm">Not fetched yet</div>
                            @elseif($forecastStatus['success'])
                                <div class="badge badge-success badge-sm">OK</div>
                                <span class="text-xs text-base-content/60">{{ \Carbon\Carbon::parse($forecastStatus['last_attempt'] ?? null)->diffForHumans() }}</span>
                            @else
                                <div class="badge badge-error badge-sm">Error</div>
                                <span class="text-xs text-base-content/60">{{ \Carbon\Carbon::parse($forecastStatus['last_attempt'] ?? null)->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="collapse-content text-sm space-y-3">
                    {{-- Location Open-Meteo is using --}}
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-base-content/60 mb-1">Location Open-Meteo is using</p>
                        @php($requested = $this->locationConfig)
                        @php($resolved = $this->resolvedOpenMeteoLocation)
                        @if($resolved)
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                                @if($requested)
                                    <dt class="text-base-content/60">Requested</dt>
                                    <dd>{{ $requested['lat'] }}, {{ $requested['lon'] }}</dd>
                                @endif
                                <dt class="text-base-content/60">Resolved</dt>
                                <dd>{{ $resolved['lat'] }}, {{ $resolved['lon'] }}</dd>
                                @if($resolved['elevation'] !== null)
                                    <dt class="text-base-content/60">Elevation</dt>
                                    <dd>{{ number_format($resolved['elevation'], 0) }} m</dd>
                                @endif
                                @if($resolved['timezone'])
                                    <dt class="text-base-content/60">Timezone</dt>
                                    <dd>{{ $resolved['timezone'] }}@if($resolved['timezone_abbreviation']) ({{ $resolved['timezone_abbreviation'] }})@endif</dd>
                                @endif
                            </dl>
                        @elseif($requested)
                            <p class="text-base-content/60">Requested {{ $requested['lat'] }}, {{ $requested['lon'] }} — no resolved location yet (no successful fetch).</p>
                        @else
                            <p class="text-base-content/60">No active event location configured.</p>
                        @endif
                    </div>

                    {{-- Request details --}}
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-base-content/60 mb-1">Request</p>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                            <dt class="text-base-content/60">Endpoint</dt>
                            <dd class="font-mono text-xs break-all">https://api.open-meteo.com/v1/forecast</dd>
                            <dt class="text-base-content/60">Units</dt>
                            <dd>{{ $units === 'metric' ? 'Metric (°C, km/h)' : 'Imperial (°F, mph)' }}</dd>
                            <dt class="text-base-content/60">Window</dt>
                            <dd>4 days, 12 hours ahead</dd>
                        </dl>
                    </div>

                    {{-- Fetch status --}}
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-base-content/60 mb-1">Fetch status</p>
                        @if(! $openMeteoEnabled)
                            <p class="text-base-content/60">Polling is disabled — forecast has been cleared.</p>
                        @elseif($forecastStatus === null)
                            <p class="text-base-content/60">No fetch has been attempted yet.</p>
                        @else
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                                <dt class="text-base-content/60">Last attempt</dt>
                                <dd>{{ \Carbon\Carbon::parse($forecastStatus['last_attempt'] ?? null)->toDayDateTimeString() }}</dd>
                            </dl>
                            @if(! $forecastStatus['success'])
                                <p class="text-error mt-1">{{ $forecastStatus['error'] }}</p>
                            @endif
                        @endif
                    </div>
                </div>
            </div>

            {{-- NWS Alerts --}}
            <div class="collapse collapse-arrow bg-base-100">
                <input type="checkbox" aria-label="NWS Alerts troubleshooting details" />
                <div class="collapse-title p-3 min-h-0 pr-10">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-3 relative z-10">
                            <input type="checkbox" class="toggle toggle-sm toggle-primary"
                                wire:click.stop="toggleNws"
                                @checked($nwsEnabled)
                                wire:confirm="{{ $nwsEnabled ? 'Disable NWS Alerts? Stored NWS alerts will be cleared. Manual alerts are unaffected.' : 'Enable NWS alert polling?' }}" />
                            <span class="text-sm font-medium">NWS Alerts</span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if(! $nwsEnabled)
                                <div class="badge badge-ghost badge-sm">Disabled</div>
                            @elseif($alertsStatus === null)
                                <div class="badge badge-ghost badge-sm">Not fetched yet</div>
                            @elseif($alertsStatus['success'])
                                <div class="badge badge-success badge-sm">OK</div>
                                <span class="text-xs text-base-content/60">{{ \Carbon\Carbon::parse($alertsStatus['last_attempt'] ?? null)->diffForHumans() }}</span>
                            @else
                                <div class="badge badge-error badge-sm">Error</div>
                                <span class="text-xs text-base-content/60">{{ \Carbon\Carbon::parse($alertsStatus['last_attempt'] ?? null)->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="collapse-content text-sm space-y-3">
                    {{-- Location NWS thinks you are in --}}
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-base-content/60 mb-1">Location NWS thinks you are in</p>
                        @php($requested = $this->locationConfig)
                        @php($resolved = $this->resolvedNwsLocation)
                        @if($resolved)
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                                @if($requested)
                                    <dt class="text-base-content/60">Requested</dt>
                                    <dd>{{ $requested['lat'] }}, {{ $requested['lon'] }}</dd>
                                @endif
                                @if($resolved['city'] && $resolved['state'])
                                    <dt class="text-base-content/60">City</dt>
                                    <dd>{{ $resolved['city'] }}, {{ $resolved['state'] }}</dd>
                                @endif
                                <dt class="text-base-content/60">Forecast zone</dt>
                                <dd class="font-mono">{{ $resolved['zone'] }}</dd>
                                <dt class="text-base-content/60">County zone</dt>
                                <dd class="font-mono">{{ $resolved['county'] }}</dd>
                            </dl>
                        @elseif($requested)
                            <p class="text-base-content/60">Requested {{ $requested['lat'] }}, {{ $requested['lon'] }} — NWS has not resolved a zone/county yet.</p>
                        @else
                            <p class="text-base-content/60">No active event location configured.</p>
                        @endif
                        @if($resolved || $requested)
                            <button wire:click="clearNwsLocationCache"
                                wire:confirm="Clear the cached NWS zone, county, and city for the active event's coordinates? NWS will re-resolve on the next poll."
                                class="btn btn-outline btn-xs mt-2">
                                <x-icon name="phosphor-arrow-clockwise" class="w-3 h-3" />
                                Clear cached location
                            </button>
                        @endif
                    </div>

                    {{-- Request details --}}
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-base-content/60 mb-1">Request</p>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                            <dt class="text-base-content/60">Points</dt>
                            <dd class="font-mono text-xs break-all">
                                @if($requested)
                                    https://api.weather.gov/points/{{ number_format($requested['lat'], 4, '.', '') }},{{ number_format($requested['lon'], 4, '.', '') }}
                                @else
                                    <span class="text-base-content/50">(no coordinates)</span>
                                @endif
                            </dd>
                            <dt class="text-base-content/60">Alerts</dt>
                            <dd class="font-mono text-xs break-all">
                                @if($resolved)
                                    https://api.weather.gov/alerts/active?zone={{ $resolved['county'] }},{{ $resolved['zone'] }}
                                @else
                                    <span class="text-base-content/50">(zone/county unresolved)</span>
                                @endif
                            </dd>
                            <dt class="text-base-content/60">Contact</dt>
                            <dd class="break-all">{{ \App\Models\Setting::get('contact_email') ?? 'admin@fielddaycommander.org' }}</dd>
                        </dl>
                    </div>

                    {{-- Alert filtering --}}
                    <details class="border border-base-300 rounded px-3 py-2">
                        <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-base-content/60">
                            Event types shown (NWS alerts outside this list are ignored)
                        </summary>
                        <ul class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
                            @foreach($this->nwsAllowedEvents as $eventType)
                                <li>{{ $eventType }}</li>
                            @endforeach
                        </ul>
                    </details>

                    {{-- Fetch status --}}
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-base-content/60 mb-1">Fetch status</p>
                        @if(! $nwsEnabled)
                            <p class="text-base-content/60">Polling is disabled — stored NWS alerts have been cleared (manual alerts preserved).</p>
                        @elseif($alertsStatus === null)
                            <p class="text-base-content/60">No fetch has been attempted yet.</p>
                        @else
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                                <dt class="text-base-content/60">Last attempt</dt>
                                <dd>{{ \Carbon\Carbon::parse($alertsStatus['last_attempt'] ?? null)->toDayDateTimeString() }}</dd>
                            </dl>
                            @if(! $alertsStatus['success'])
                                <p class="text-error mt-1">{{ $alertsStatus['error'] }}</p>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
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
                        {{ $currentOverride['temperature'] ?? '—' }}°{{ $units === 'metric' ? 'C' : 'F' }} ·
                        Wind {{ $currentOverride['wind_speed'] ?? '—' }} {{ $units === 'metric' ? 'km/h' : 'mph' }} {{ $currentOverride['wind_direction'] ?? '' }} ·
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
                        <label for="weather-temperature" class="label label-text text-xs">Temp ({{ $units === 'metric' ? '°C' : '°F' }})</label>
                        <input id="weather-temperature" wire:model="temperature" type="number" class="input input-bordered input-sm w-full" placeholder="72" />
                        @error('temperature') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="weather-wind-speed" class="label label-text text-xs">Wind ({{ $units === 'metric' ? 'km/h' : 'mph' }})</label>
                        <input id="weather-wind-speed" wire:model="windSpeed" type="number" class="input input-bordered input-sm w-full" placeholder="10" />
                        @error('windSpeed') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="weather-wind-direction" class="label label-text text-xs">Direction</label>
                        <select id="weather-wind-direction" wire:model="windDirection" class="select select-bordered select-sm w-full">
                            @foreach(['N','NE','E','SE','S','SW','W','NW'] as $dir)
                                <option value="{{ $dir }}">{{ $dir }}</option>
                            @endforeach
                        </select>
                        @error('windDirection') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="weather-precipitation-chance" class="label label-text text-xs">Rain %</label>
                        <input id="weather-precipitation-chance" wire:model="precipitationChance" type="number" min="0" max="100" class="input input-bordered input-sm w-full" placeholder="20" />
                        @error('precipitationChance') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="weather-notes" class="label label-text text-xs">Notes (optional)</label>
                    <textarea id="weather-notes" wire:model="notes" class="textarea textarea-bordered w-full text-sm" rows="2"
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
                    <x-icon name="phosphor-warning-duotone" class="w-5 h-5 shrink-0" />
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
                    <label for="weather-alert-message" class="label label-text text-xs">Alert Message</label>
                    <input id="weather-alert-message" wire:model="alertMessage" type="text" class="input input-bordered w-full"
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
