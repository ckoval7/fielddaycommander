<div>
    @if($isVisible)
        <div class="bg-info/10 border-b border-info/30 px-4 py-2 text-sm flex flex-wrap items-center justify-between gap-2">
            <span class="text-info font-medium">
                You're exploring FD Commander in <strong>demo mode</strong>.
                @if($expiresAt)
                    Expires in {{ $expiresAt->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}.
                @endif
            </span>

            <div class="flex items-center gap-3">
                <a href="https://fielddaycommander.org/" target="_blank" rel="noopener noreferrer"
                   class="link link-info text-xs">Learn more</a>

                <form method="POST" action="{{ route('demo.reset') }}" class="inline">
                    @csrf
                    @php
                    $roleMap = [
                        'System Administrator' => 'system_admin',
                        'Event Manager' => 'event_manager',
                        'Station Captain' => 'station_captain',
                        'Operator' => 'operator',
                    ];
                @endphp
                <input type="hidden" name="role" value="{{ $roleMap[session('dev_role_override', '')] ?? 'event_manager' }}">
                    <button type="submit" class="btn btn-xs btn-ghost text-info">Reset data</button>
                </form>
            </div>
        </div>
    @endif
</div>
