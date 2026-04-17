<div wire:poll.900s="loadData">
    @php $headerText = $locationLabel !== null ? 'Weather for '.$locationLabel : 'Weather'; @endphp
    <x-slot:title>{{ $headerText }}</x-slot:title>

    <div class="p-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold">{{ $headerText }}</h1>
                @if($isManual)
                    <span class="badge badge-warning mt-1">Manual Override Active</span>
                @endif
            </div>
            @can('manage-weather')
                <div class="flex flex-wrap gap-2">
                    <x-button
                        label="Manage Weather"
                        icon="phosphor-gear-six"
                        class="btn-outline"
                        link="{{ route('weather.manage') }}"
                    />
                </div>
            @endcan
        </div>

        {{-- Empty state --}}
        @if(! $hasData)
            <div class="flex flex-col items-center justify-center py-16 text-center text-base-content/60">
                <x-icon name="phosphor-cloud-duotone" class="w-16 h-16 mb-4 opacity-30" />
                @if($canManageWeather)
                    <p class="text-lg font-medium text-base-content/70">No weather data yet.</p>
                    <p class="text-sm mt-1 max-w-sm">Set up an active event with coordinates to enable automatic weather monitoring.</p>
                @else
                    <p class="text-lg font-medium text-base-content/70">No weather data available.</p>
                @endif
            </div>
        @else

            {{-- Current Conditions --}}
            <div class="card bg-base-100 shadow mb-4">
                <div class="card-body">
                    <h2 class="card-title text-base font-semibold text-base-content/70 uppercase tracking-wide text-xs mb-3">Current Conditions</h2>

                    @if($isManual)
                        @php $d = $manualOverride; @endphp
                        <div class="flex flex-wrap gap-6 items-center">
                            <div class="flex items-center gap-3">
                                <x-icon name="phosphor-cloud-duotone" class="w-10 h-10 text-base-content/40" />
                                @if(isset($d['temperature']))
                                    <span class="text-4xl font-bold">{{ round($d['temperature']) }}°</span>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-4 text-sm">
                                @if(isset($d['wind_speed']))
                                    <div>
                                        <span class="text-base-content/50">Wind</span>
                                        <span class="ml-1 font-medium">{{ $d['wind_direction'] ?? '' }} {{ round($d['wind_speed']) }} {{ $windUnit }}</span>
                                    </div>
                                @endif
                                @if(isset($d['precipitation_chance']))
                                    <div>
                                        <span class="text-base-content/50">Precip chance</span>
                                        <span class="ml-1 font-medium">{{ $d['precipitation_chance'] }}%</span>
                                    </div>
                                @endif
                                @if(! empty($d['notes']))
                                    <div class="w-full">
                                        <span class="text-base-content/50">Notes</span>
                                        <span class="ml-1">{{ $d['notes'] }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @else
                        @php $c = $forecast['current']; @endphp
                        <div class="flex flex-wrap gap-6 items-center">
                            <div class="flex items-center gap-3">
                                <x-icon name="{{ $this->iconFor((int) ($c['weather_code'] ?? 0)) }}" class="w-10 h-10 {{ $this->colorFor((int) ($c['weather_code'] ?? 0)) }}" />
                                <div>
                                    <span class="text-4xl font-bold">{{ round($c['temperature_2m']) }}°</span>
                                    <p class="text-sm text-base-content/60">{{ $this->labelFor((int) ($c['weather_code'] ?? 0)) }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-4 text-sm">
                                <div>
                                    <span class="text-base-content/50">Wind</span>
                                    <span class="ml-1 font-medium">{{ round($c['wind_speed_10m']) }} {{ $windUnit }}</span>
                                    @if(($c['wind_gusts_10m'] ?? 0) >= 25)
                                        <span class="ml-1 text-warning font-medium">gusts {{ round($c['wind_gusts_10m']) }} {{ $windUnit }}</span>
                                    @elseif(($c['wind_gusts_10m'] ?? 0) > 0)
                                        <span class="ml-1 text-base-content/50">gusts {{ round($c['wind_gusts_10m']) }} {{ $windUnit }}</span>
                                    @endif
                                </div>
                                <div>
                                    <span class="text-base-content/50">Precip</span>
                                    <span class="ml-1 font-medium">{{ $c['precipitation'] ?? 0 }} mm</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Next 12 Hours --}}
            @if(! $isManual && ! empty($this->hourlyData))
                <div class="card bg-base-100 shadow mb-4">
                    <div class="card-body">
                        <h2 class="card-title text-base font-semibold text-base-content/70 uppercase tracking-wide text-xs mb-3">Next 12 Hours</h2>
                        <div class="overflow-x-auto">
                            <table class="table table-sm text-center">
                                <thead>
                                    <tr class="text-xs text-base-content/50">
                                        <th>Time</th>
                                        <th></th>
                                        <th>Temp</th>
                                        <th>Precip %</th>
                                        <th>Wind {{ $windUnit }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($this->hourlyData as $hour)
                                        @php
                                            $cape = (float) ($hour['cape'] ?? 0);
                                            $capeClass = match(true) {
                                                $cape >= 1500 => 'bg-error/20',
                                                $cape >= 500  => 'bg-warning/20',
                                                default        => '',
                                            };
                                            $capeLabel = match(true) {
                                                $cape >= 1500 => 'Significant Lightning Risk',
                                                $cape >= 500  => 'Elevated Lightning Risk',
                                                default        => '',
                                            };
                                        @endphp
                                        <tr class="{{ $capeClass }}">
                                            <td class="font-medium text-xs">{{ $hour['time'] }}</td>
                                            <td>
                                                @if($hour['weather_code'] !== null)
                                                    <x-icon name="{{ $this->iconFor($hour['weather_code']) }}" class="w-6 h-6 mx-auto {{ $this->colorFor($hour['weather_code']) }}" />
                                                @endif
                                            </td>
                                            <td>{{ $hour['temperature'] !== null ? round($hour['temperature']).'°' : '—' }}</td>
                                            <td>{{ $hour['precip_probability'] !== null ? $hour['precip_probability'].'%' : '—' }}</td>
                                            <td>
                                                {{ $hour['wind_speed'] !== null ? round($hour['wind_speed']) : '—' }}
                                                @if($capeLabel)
                                                    <div class="text-xs font-semibold {{ $cape >= 1500 ? 'text-red-700 dark:text-error' : 'text-amber-700 dark:text-warning' }}">{{ $capeLabel }}</div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- 3-Day Forecast --}}
            @if(! $isManual && ! empty($this->dailyData))
                <div class="mb-4">
                    <h2 class="text-xs font-semibold text-base-content/70 uppercase tracking-wide mb-3">3-Day Forecast</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        @foreach($this->dailyData as $day)
                            <div class="card bg-base-100 shadow">
                                <div class="card-body items-center text-center py-4">
                                    <p class="text-xs font-semibold text-base-content/60 uppercase">{{ $day['date'] }}</p>
                                    @if($day['weather_code'] !== null)
                                        <x-icon name="{{ $this->iconFor($day['weather_code']) }}" class="w-8 h-8 my-1 {{ $this->colorFor($day['weather_code']) }}" />
                                        <p class="text-xs text-base-content/60">{{ $this->labelFor($day['weather_code']) }}</p>
                                    @endif
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="font-bold">{{ $day['high'] !== null ? round($day['high']).'°' : '—' }}</span>
                                        <span class="text-base-content/40">/</span>
                                        <span class="text-base-content/60">{{ $day['low'] !== null ? round($day['low']).'°' : '—' }}</span>
                                    </div>
                                    <div class="text-xs text-base-content/50 mt-1 space-y-0.5">
                                        @if($day['precip_chance'] !== null)
                                            <p>Precip {{ $day['precip_chance'] }}%</p>
                                        @endif
                                        @if($day['wind_max'] !== null)
                                            <p>Wind {{ round($day['wind_max']) }} {{ $windUnit }} @if($day['gusts_max'] !== null)(gusts {{ round($day['gusts_max']) }} {{ $windUnit }})@endif</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Active Alerts --}}
            @if(! empty($alerts))
                <div class="mb-4 space-y-2">
                    <h2 class="text-xs font-semibold text-base-content/70 uppercase tracking-wide mb-2">Active Alerts</h2>
                    @foreach($alerts as $alert)
                        @php $isLocalAlert = ($alert['event'] ?? '') === 'Local Alert'; @endphp
                        <div
                            x-data="{ expanded: false }"
                            class="{{ $isLocalAlert ? 'alert alert-error' : 'alert bg-amber-100 text-amber-950 dark:bg-yellow-400/20 dark:text-yellow-100' }} flex-col items-start gap-1"
                        >
                            <div class="flex items-start justify-between w-full gap-2">
                                <div class="flex items-center gap-2">
                                    <x-icon name="{{ $isLocalAlert ? 'phosphor-warning-octagon-duotone' : 'phosphor-warning-duotone' }}" class="w-5 h-5 shrink-0" />
                                    <span class="font-semibold text-sm">{{ $alert['headline'] ?? $alert['event'] }}</span>
                                </div>
                                @if(! $isLocalAlert && ! empty($alert['description']))
                                    <button
                                        @click="expanded = !expanded"
                                        type="button"
                                        class="btn btn-ghost btn-xs shrink-0"
                                    >
                                        <x-icon name="phosphor-caret-down" class="w-4 h-4" x-bind:class="expanded ? 'rotate-180' : ''" />
                                    </button>
                                @endif
                            </div>
                            @if(! $isLocalAlert && ! empty($alert['description']))
                                <div x-show="expanded" x-cloak class="text-sm mt-2 whitespace-pre-line">{{ $alert['description'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Data Source Footer --}}
            <div class="text-xs text-base-content/40 text-center pt-2 pb-4">
                @if($isManual)
                    Manual override active — entered by {{ $manualOverride['updated_by'] ?? 'unknown' }}
                    @if(! empty($manualOverride['updated_at']))
                        at {{ \Carbon\Carbon::parse($manualOverride['updated_at'])->format('M j, Y g:i A') }}
                    @endif
                @elseif($lastFetch)
                    Updated {{ \Carbon\Carbon::parse($lastFetch)->diffForHumans() }} · Open-Meteo / NWS
                @endif
            </div>

        @endif
    </div>
</div>
