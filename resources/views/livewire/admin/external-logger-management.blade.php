<div>
    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-end">
                <a href="{{ route('admin.import-adif') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
                    <x-icon name="o-arrow-up-tray" class="w-4 h-4" />
                    Import ADIF
                </a>
            </div>
            @if (! $hasActiveEvent)
                <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-4">
                    <p class="text-yellow-800 dark:text-yellow-200">No active event configuration. External loggers require an active event.</p>
                </div>
            @else
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg p-4">
                    <p class="text-red-800 dark:text-red-200"><strong>Security Notice:</strong> UDP logging should only be enabled on private, firewalled networks. Do not enable UDP logging on internet-hosted servers, as UDP is unauthenticated and anyone who can reach the port can send arbitrary data.</p>
                </div>
                {{-- N1MM Logger+ Section --}}
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6" wire:poll.5s="pollStatus">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">N1MM Logger+</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Receive live QSO data via UDP broadcast</p>
                        </div>
                        <button
                            wire:click="toggleN1mm"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 {{ $n1mmEnabled ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-600' }}"
                        >
                            <span class="sr-only">Toggle N1MM listener</span>
                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $n1mmEnabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
                        </button>
                    </div>

                    {{-- Process Status Indicator --}}
                    <div class="flex items-center gap-2 mb-4">
                        @if ($processStatus === 'running')
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                            </span>
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                Listening on port {{ $heartbeat['port'] ?? $n1mmPort }}
                                &middot; {{ $heartbeat['packets_received'] ?? 0 }} packets
                                &middot; {{ $heartbeat['errors'] ?? 0 }} errors
                                @if (! empty($heartbeat['last_packet_at']))
                                    &middot; last packet {{ \Carbon\Carbon::parse($heartbeat['last_packet_at'])->diffForHumans() }}
                                @endif
                            </span>
                        @elseif ($processStatus === 'starting')
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-yellow-500"></span>
                            </span>
                            <span class="text-sm text-yellow-600 dark:text-yellow-400">Starting listener...</span>
                        @elseif ($processStatus === 'crashed')
                            <span class="inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                            <span class="text-sm text-red-600 dark:text-red-400">
                                Process crashed &middot; Restarting...
                            </span>
                            <button
                                wire:click="restartProcess"
                                class="ml-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
                            >
                                Retry
                            </button>
                        @else
                            <span class="inline-flex rounded-full h-2.5 w-2.5 bg-gray-400"></span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">Stopped</span>
                        @endif
                    </div>

                    {{-- Uptime --}}
                    @if ($processStatus === 'running' && ! empty($heartbeat['started_at']))
                        <div class="text-xs text-gray-400 dark:text-gray-500 mb-4">
                            Uptime: {{ \Carbon\Carbon::parse($heartbeat['started_at'])->diffForHumans(null, true) }}
                        </div>
                    @endif

                    {{-- Last Log Received --}}
                    @if ($processStatus === 'running' || $lastLog !== null)
                        <div x-data="{ open: false }" class="mb-4">
                            <button
                                type="button"
                                @click="open = !open"
                                class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors"
                            >
                                <x-icon name="o-chevron-right" class="w-4 h-4 transition-transform duration-150" x-bind:class="open ? 'rotate-90' : ''" />
                                <span>Last Log Received</span>
                                @if ($lastLog !== null)
                                    @if ($lastLog['accepted'])
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Accepted</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300">{{ $lastLog['rejection_reason'] }}</span>
                                    @endif
                                @endif
                            </button>
                            <div x-show="open" x-transition class="mt-2 ml-5 text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                @if ($lastLog !== null)
                                    <div><span class="font-medium text-gray-700 dark:text-gray-300">Callsign:</span> {{ $lastLog['callsign'] }}</div>
                                    @if ($lastLog['band'])
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Band:</span> {{ $lastLog['band'] }}</div>
                                    @endif
                                    @if ($lastLog['mode'])
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Mode:</span> {{ $lastLog['mode'] }}</div>
                                    @endif
                                    @if ($lastLog['section'])
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Section:</span> {{ $lastLog['section'] }}</div>
                                    @endif
                                    <div><span class="font-medium text-gray-700 dark:text-gray-300">QSO Time:</span> {{ \Carbon\Carbon::parse($lastLog['qso_time'])->format('Y-m-d H:i') }} UTC</div>
                                    <div><span class="font-medium text-gray-700 dark:text-gray-300">Received:</span> {{ \Carbon\Carbon::parse($lastLog['received_at'])->diffForHumans() }}</div>
                                @else
                                    <p class="text-gray-400 dark:text-gray-500">No contacts received yet.</p>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <label for="n1mmPort" class="block text-sm font-medium text-gray-700 dark:text-gray-300">UDP Port</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input
                                type="number"
                                id="n1mmPort"
                                wire:model="n1mmPort"
                                wire:change="updatePort"
                                min="1024"
                                max="65535"
                                class="block w-32 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                {{ $n1mmEnabled ? 'disabled' : '' }}
                            >
                            <span class="text-sm text-gray-500 dark:text-gray-400">Default: 12060</span>
                        </div>
                        @error('n1mmPort')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @if ($n1mmEnabled)
                            <p class="mt-1 text-sm text-yellow-600 dark:text-yellow-400">Disable the listener to change the port.</p>
                        @endif
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Setup Instructions</h4>
                        <ol class="text-sm text-gray-500 dark:text-gray-400 list-decimal list-inside space-y-1">
                            <li>In N1MM+, go to Config &gt; Configure Ports &gt; Broadcast Data</li>
                            <li>Check "Contacts" and "Radio" checkboxes</li>
                            <li>Ensure your firewall allows inbound UDP on port {{ $n1mmPort }} (e.g., <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 py-0.5 rounded">sudo ufw allow {{ $n1mmPort }}/udp</code>)</li>
                            <li>Set the destination IP to this server's address with port {{ $n1mmPort }}</li>
                            <li>Optionally configure Station hostnames on the Stations page for automatic matching</li>
                        </ol>
                    </div>
                </div>

                {{-- WSJTX / JTDX Section --}}
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6" wire:poll.5s="pollStatus">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">WSJTX / JTDX</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Receive live QSO data via UDP ADIF broadcast</p>
                        </div>
                        <button
                            wire:click="toggleWsjtx"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 {{ $wsjtxEnabled ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-600' }}"
                        >
                            <span class="sr-only">Toggle WSJTX listener</span>
                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $wsjtxEnabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
                        </button>
                    </div>

                    {{-- Process Status Indicator --}}
                    <div class="flex items-center gap-2 mb-4">
                        @if ($wsjtxProcessStatus === 'running')
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                            </span>
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                Listening on port {{ $wsjtxHeartbeat['port'] ?? $wsjtxPort }}
                                &middot; {{ $wsjtxHeartbeat['packets_received'] ?? 0 }} packets
                                &middot; {{ $wsjtxHeartbeat['errors'] ?? 0 }} errors
                                @if (! empty($wsjtxHeartbeat['last_packet_at']))
                                    &middot; last packet {{ \Carbon\Carbon::parse($wsjtxHeartbeat['last_packet_at'])->diffForHumans() }}
                                @endif
                            </span>
                        @elseif ($wsjtxProcessStatus === 'starting')
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-yellow-500"></span>
                            </span>
                            <span class="text-sm text-yellow-600 dark:text-yellow-400">Starting listener...</span>
                        @elseif ($wsjtxProcessStatus === 'crashed')
                            <span class="inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                            <span class="text-sm text-red-600 dark:text-red-400">
                                Process crashed &middot; Restarting...
                            </span>
                            <button
                                wire:click="restartWsjtxProcess"
                                class="ml-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
                            >
                                Retry
                            </button>
                        @else
                            <span class="inline-flex rounded-full h-2.5 w-2.5 bg-gray-400"></span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">Stopped</span>
                        @endif
                    </div>

                    {{-- Uptime --}}
                    @if ($wsjtxProcessStatus === 'running' && ! empty($wsjtxHeartbeat['started_at']))
                        <div class="text-xs text-gray-400 dark:text-gray-500 mb-4">
                            Uptime: {{ \Carbon\Carbon::parse($wsjtxHeartbeat['started_at'])->diffForHumans(null, true) }}
                        </div>
                    @endif

                    {{-- Last Log Received --}}
                    @if ($wsjtxProcessStatus === 'running' || $wsjtxLastLog !== null)
                        <div x-data="{ open: false }" class="mb-4">
                            <button
                                type="button"
                                @click="open = !open"
                                class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors"
                            >
                                <x-icon name="o-chevron-right" class="w-4 h-4 transition-transform duration-150" x-bind:class="open ? 'rotate-90' : ''" />
                                <span>Last Log Received</span>
                                @if ($wsjtxLastLog !== null)
                                    @if ($wsjtxLastLog['accepted'])
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Accepted</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300">{{ $wsjtxLastLog['rejection_reason'] }}</span>
                                    @endif
                                @endif
                            </button>
                            <div x-show="open" x-transition class="mt-2 ml-5 text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                @if ($wsjtxLastLog !== null)
                                    <div><span class="font-medium text-gray-700 dark:text-gray-300">Callsign:</span> {{ $wsjtxLastLog['callsign'] }}</div>
                                    @if ($wsjtxLastLog['band'])
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Band:</span> {{ $wsjtxLastLog['band'] }}</div>
                                    @endif
                                    @if ($wsjtxLastLog['mode'])
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Mode:</span> {{ $wsjtxLastLog['mode'] }}</div>
                                    @endif
                                    @if ($wsjtxLastLog['section'])
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Section:</span> {{ $wsjtxLastLog['section'] }}</div>
                                    @endif
                                    <div><span class="font-medium text-gray-700 dark:text-gray-300">QSO Time:</span> {{ \Carbon\Carbon::parse($wsjtxLastLog['qso_time'])->format('Y-m-d H:i') }} UTC</div>
                                    <div><span class="font-medium text-gray-700 dark:text-gray-300">Received:</span> {{ \Carbon\Carbon::parse($wsjtxLastLog['received_at'])->diffForHumans() }}</div>
                                @else
                                    <p class="text-gray-400 dark:text-gray-500">No contacts received yet.</p>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <label for="wsjtxPort" class="block text-sm font-medium text-gray-700 dark:text-gray-300">UDP Port</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input
                                type="number"
                                id="wsjtxPort"
                                wire:model="wsjtxPort"
                                wire:change="updateWsjtxPort"
                                min="1024"
                                max="65535"
                                class="block w-32 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                {{ $wsjtxEnabled ? 'disabled' : '' }}
                            >
                            <span class="text-sm text-gray-500 dark:text-gray-400">Default: 2237</span>
                        </div>
                        @error('wsjtxPort')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @if ($wsjtxEnabled)
                            <p class="mt-1 text-sm text-yellow-600 dark:text-yellow-400">Disable the listener to change the port.</p>
                        @endif
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Setup Instructions</h4>
                        <ol class="text-sm text-gray-500 dark:text-gray-400 list-decimal list-inside space-y-1">
                            <li>In WSJTX, go to File &gt; Settings &gt; Reporting &gt; UDP Server</li>
                            <li>Set the UDP server address to this server's IP and port {{ $wsjtxPort }}</li>
                            <li>Under Outgoing Interfaces, select the network interface that can reach this server</li>
                            <li>Ensure your firewall allows inbound UDP on port {{ $wsjtxPort }} (e.g., <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 py-0.5 rounded">sudo ufw allow {{ $wsjtxPort }}/udp</code>)</li>
                        </ol>
                    </div>
                </div>
                {{-- UDP ADIF (fldigi, etc.) Section --}}
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6" wire:poll.5s="pollStatus">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">UDP ADIF (fldigi, etc.)</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Receive live QSO data via plain ADIF text over UDP</p>
                        </div>
                        <button
                            wire:click="toggleUdpAdif"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 {{ $udpAdifEnabled ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-600' }}"
                        >
                            <span class="sr-only">Toggle UDP ADIF listener</span>
                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $udpAdifEnabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
                        </button>
                    </div>

                    {{-- Process Status Indicator --}}
                    <div class="flex items-center gap-2 mb-4">
                        @if ($udpAdifProcessStatus === 'running')
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                            </span>
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                Listening on port {{ $udpAdifHeartbeat['port'] ?? $udpAdifPort }}
                                &middot; {{ $udpAdifHeartbeat['packets_received'] ?? 0 }} packets
                                &middot; {{ $udpAdifHeartbeat['errors'] ?? 0 }} errors
                                @if (! empty($udpAdifHeartbeat['last_packet_at']))
                                    &middot; last packet {{ \Carbon\Carbon::parse($udpAdifHeartbeat['last_packet_at'])->diffForHumans() }}
                                @endif
                            </span>
                        @elseif ($udpAdifProcessStatus === 'starting')
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-yellow-500"></span>
                            </span>
                            <span class="text-sm text-yellow-600 dark:text-yellow-400">Starting listener...</span>
                        @elseif ($udpAdifProcessStatus === 'crashed')
                            <span class="inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                            <span class="text-sm text-red-600 dark:text-red-400">
                                Process crashed &middot; Restarting...
                            </span>
                            <button
                                wire:click="restartUdpAdifProcess"
                                class="ml-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
                            >
                                Retry
                            </button>
                        @else
                            <span class="inline-flex rounded-full h-2.5 w-2.5 bg-gray-400"></span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">Stopped</span>
                        @endif
                    </div>

                    {{-- Uptime --}}
                    @if ($udpAdifProcessStatus === 'running' && ! empty($udpAdifHeartbeat['started_at']))
                        <div class="text-xs text-gray-400 dark:text-gray-500 mb-4">
                            Uptime: {{ \Carbon\Carbon::parse($udpAdifHeartbeat['started_at'])->diffForHumans(null, true) }}
                        </div>
                    @endif

                    {{-- Last Log Received --}}
                    @if ($udpAdifProcessStatus === 'running' || $udpAdifLastLog !== null)
                        <div x-data="{ open: false }" class="mb-4">
                            <button
                                type="button"
                                @click="open = !open"
                                class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors"
                            >
                                <x-icon name="o-chevron-right" class="w-4 h-4 transition-transform duration-150" x-bind:class="open ? 'rotate-90' : ''" />
                                <span>Last Log Received</span>
                                @if ($udpAdifLastLog !== null)
                                    @if ($udpAdifLastLog['accepted'])
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Accepted</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300">{{ $udpAdifLastLog['rejection_reason'] }}</span>
                                    @endif
                                @endif
                            </button>
                            <div x-show="open" x-transition class="mt-2 ml-5 text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                @if ($udpAdifLastLog !== null)
                                    <div><span class="font-medium text-gray-700 dark:text-gray-300">Callsign:</span> {{ $udpAdifLastLog['callsign'] }}</div>
                                    @if ($udpAdifLastLog['band'])
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Band:</span> {{ $udpAdifLastLog['band'] }}</div>
                                    @endif
                                    @if ($udpAdifLastLog['mode'])
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Mode:</span> {{ $udpAdifLastLog['mode'] }}</div>
                                    @endif
                                    @if ($udpAdifLastLog['section'])
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Section:</span> {{ $udpAdifLastLog['section'] }}</div>
                                    @endif
                                    <div><span class="font-medium text-gray-700 dark:text-gray-300">QSO Time:</span> {{ \Carbon\Carbon::parse($udpAdifLastLog['qso_time'])->format('Y-m-d H:i') }} UTC</div>
                                    <div><span class="font-medium text-gray-700 dark:text-gray-300">Received:</span> {{ \Carbon\Carbon::parse($udpAdifLastLog['received_at'])->diffForHumans() }}</div>
                                @else
                                    <p class="text-gray-400 dark:text-gray-500">No contacts received yet.</p>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <label for="udpAdifPort" class="block text-sm font-medium text-gray-700 dark:text-gray-300">UDP Port</label>
                        <div class="mt-1 flex items-center gap-2">
                            <input
                                type="number"
                                id="udpAdifPort"
                                wire:model="udpAdifPort"
                                wire:change="updateUdpAdifPort"
                                min="1024"
                                max="65535"
                                class="block w-32 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                {{ $udpAdifEnabled ? 'disabled' : '' }}
                            >
                            <span class="text-sm text-gray-500 dark:text-gray-400">Default: 2238</span>
                        </div>
                        @error('udpAdifPort')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @if ($udpAdifEnabled)
                            <p class="mt-1 text-sm text-yellow-600 dark:text-yellow-400">Disable the listener to change the port.</p>
                        @endif
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Setup Instructions</h4>
                        <ol class="text-sm text-gray-500 dark:text-gray-400 list-decimal list-inside space-y-1">
                            <li>Configure your logging application to send ADIF records via UDP</li>
                            <li>Set the destination to this server's IP and port {{ $udpAdifPort }}</li>
                            <li>In fldigi: Configure &gt; Config Dialog &gt; Logging &gt; Cloud-UDP &gt; set UDP address and port and check enable</li>
                            <li>Ensure your firewall allows inbound UDP on port {{ $udpAdifPort }} (e.g., <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 py-0.5 rounded">sudo ufw allow {{ $udpAdifPort }}/udp</code>)</li>
                        </ol>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
