<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            External Logger Management
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (! $hasActiveEvent)
                <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-4">
                    <p class="text-yellow-800 dark:text-yellow-200">No active event configuration. External loggers require an active event.</p>
                </div>
            @else
                {{-- N1MM Logger+ Section --}}
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
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

                    <div class="flex items-center gap-2 mb-4">
                        <span class="inline-flex items-center gap-1.5 text-sm">
                            <span class="h-2 w-2 rounded-full {{ $n1mmEnabled ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                            {{ $n1mmEnabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>

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
                            <li>Set the destination IP to this server's address with port {{ $n1mmPort }}</li>
                            <li>Optionally configure Station hostnames on the Stations page for automatic matching</li>
                        </ol>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
