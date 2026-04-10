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
                    <button type="submit" class="btn btn-xs btn-ghost text-info">Start over</button>
                </form>
            </div>
        </div>
    @endif
</div>
