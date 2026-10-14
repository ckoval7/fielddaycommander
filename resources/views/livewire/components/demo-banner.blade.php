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
                @if($inviteUrl)
                    <div class="relative" x-data="{ open: false, copied: false }" @keydown.escape.window="open = false">
                        <button type="button" class="btn btn-xs btn-ghost text-info" @click="open = !open" :aria-expanded="open">
                            <x-icon name="o-user-plus" class="w-4 h-4" />
                            Invite
                        </button>

                        <div x-show="open" x-cloak @click.outside="open = false"
                             class="absolute right-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-box bg-base-100 p-4 shadow-lg border border-base-300 text-base-content">
                            <p class="font-semibold">Invite others to this sandbox</p>
                            <p class="text-xs text-base-content/60 mt-1">
                                Anyone with this link can join and change the same demo data.
                                {{ $participantCount }} of {{ $maxParticipants }} spots taken.
                            </p>

                            @if($isFull)
                                <p class="text-xs text-warning mt-2">This sandbox is full, so the link won't admit anyone new.</p>
                            @endif

                            <div class="join w-full mt-3">
                                <input type="text" readonly value="{{ $inviteUrl }}" x-ref="inviteUrl"
                                       aria-label="Invite link"
                                       class="input input-sm input-bordered join-item w-full" @focus="$el.select()">
                                <button type="button" class="btn btn-sm btn-primary join-item"
                                        @click="navigator.clipboard.writeText($refs.inviteUrl.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                </button>
                            </div>

                            <button type="button" class="btn btn-xs btn-ghost mt-2" wire:click="rotateInvite"
                                    wire:confirm="Make a new link? The current one will stop letting people in.">
                                Make a new link
                            </button>
                        </div>
                    </div>
                @endif

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
