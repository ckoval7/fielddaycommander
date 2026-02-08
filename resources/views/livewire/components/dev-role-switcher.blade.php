<div x-data x-on:role-switched.window="window.location.reload()">
    @if(config('developer.enabled') && auth()->check())
        <div class="bg-purple-100 dark:bg-purple-900/30 border-l-4 border-purple-500 text-purple-900 dark:text-purple-200 shadow-md rounded-none px-4 py-2" role="status">
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4 w-full">
                {{-- Left: Icon + Label --}}
                <div class="flex items-center gap-2 shrink-0">
                    <x-icon name="o-wrench" class="w-4 h-4 text-purple-600 dark:text-purple-400" />
                    <span class="font-bold text-xs uppercase tracking-wide">Role Switcher</span>
                    @if($isActive)
                        <span class="badge badge-sm badge-primary">active</span>
                    @endif
                </div>

                {{-- Center: Controls --}}
                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2 sm:gap-3 flex-1 min-w-0">
                    {{-- Role Select --}}
                    <div class="flex items-center gap-1.5">
                        <label for="dev-role" class="text-xs font-medium shrink-0">Role:</label>
                        <select
                            id="dev-role"
                            wire:model.live="role"
                            class="select select-xs select-bordered bg-base-100 text-base-content min-w-[140px]"
                        >
                            <option value="">— No Override —</option>
                            @foreach($roles as $availableRole)
                                <option value="{{ $availableRole }}">{{ $availableRole }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Callsign Input --}}
                    <div class="flex items-center gap-1.5">
                        <label for="dev-callsign" class="text-xs font-medium shrink-0">Callsign:</label>
                        <input
                            id="dev-callsign"
                            type="text"
                            wire:model.live.debounce.500ms="callSign"
                            class="input input-xs input-bordered bg-base-100 text-base-content w-28 uppercase"
                            placeholder="e.g. W1AW"
                        />
                    </div>
                </div>

                {{-- Right: Reset Button --}}
                @if($isActive)
                    <button
                        wire:click="resetOverrides"
                        type="button"
                        class="btn btn-xs btn-outline border-purple-400 text-purple-700 dark:text-purple-300 hover:bg-purple-200 dark:hover:bg-purple-800 hover:border-purple-500 shrink-0"
                    >
                        Reset
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>
