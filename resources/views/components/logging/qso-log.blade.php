{{--
QSO Log Component

Chat-style log shared by live logging and paper-log transcription: the QSO
history (oldest → newest) with the exchange composer docked underneath.

The enclosing Alpine scope must provide recalledContactId and recalledUuid.
The page's sticky status bar should carry data-log-sticky-bar so phones can
size the log to the space left below it.

Props:
- $title: string - Card heading
- $subtitle: string|null - Muted text beside the heading
- $isEmpty: bool - Whether there are no server-confirmed contacts
- $hasPending: string - JS expression that is true while client-side contacts are shown
- $example: string - Example exchange shown in the empty state
- $timeHeading: string - Heading for the desktop time column

Slots:
- $cards: Mobile card list items
- $rows: Desktop table rows
- $extraColumns: Extra desktop column headings, after Section
- $slot: The docked composer
--}}

@props([
    'title',
    'subtitle' => null,
    'isEmpty' => false,
    'hasPending' => 'false',
    'example' => 'W1AW 3A CT',
    'timeHeading' => 'Time',
])

<div
    class="bg-base-100 sm:rounded-lg sm:shadow-sm border-y sm:border border-base-300 flex flex-col max-sm:h-[70dvh]"
    x-data="{
        aligning: true,
        {{-- Phones: size the log to fill the screen below the sticky header and status bar,
             and on load scroll so the input sits at the bottom of the screen. --}}
        fitToViewport() {
            if (!window.matchMedia('(max-width: 639px)').matches) {
                this.$el.style.removeProperty('height');
                return;
            }
            const content = this.$el.parentElement;
            const stickyBarHeight = document.querySelector('[data-log-sticky-bar]')?.offsetHeight ?? 0;
            const headerHeight = document.querySelector('header.sticky')?.offsetHeight ?? 0;
            const aboveCard = this.$el.getBoundingClientRect().top - content.getBoundingClientRect().top;
            const height = window.innerHeight - headerHeight - stickyBarHeight - aboveCard;
            this.$el.style.height = Math.max(320, height) + 'px';

            if (this.aligning) {
                requestAnimationFrame(() => window.scrollBy(0, this.$el.getBoundingClientRect().bottom - window.innerHeight));
            }
        },
    }"
    x-init="
        const observer = new ResizeObserver(() => fitToViewport());
        observer.observe(document.body);
        observer.observe(document.querySelector('header.sticky') ?? document.body);
        setTimeout(() => aligning = false, 1500);
    "
    @resize.window.debounce.150ms="fitToViewport()"
>
    <div class="px-4 py-2.5 flex items-baseline justify-between border-b border-base-300">
        <h2 class="font-semibold">{{ $title }}</h2>
        @if($subtitle)
            <span class="text-xs text-base-content/50">{{ $subtitle }}</span>
        @endif
    </div>

    {{-- History: stays pinned to the bottom so the newest QSO sits right above the input --}}
    <div
        class="max-sm:flex-1 max-sm:min-h-0 sm:h-80 overflow-y-auto overflow-x-auto"
        x-data="{
            pinned: true,
            scrollToBottom() {
                this.$el.scrollTop = this.$el.scrollHeight;
                this.pinned = true;
            },
            revealRecalled(uuid, contactId) {
                this.$nextTick(() => {
                    const row = [...this.$el.querySelectorAll('[aria-current=true]')].find(el => el.offsetParent !== null);
                    if (!row) {
                        this.scrollToBottom();
                        return;
                    }
                    const box = this.$el.getBoundingClientRect();
                    const rowBox = row.getBoundingClientRect();
                    const headerHeight = this.$el.querySelector('thead').offsetHeight;
                    if (rowBox.top < box.top + headerHeight) {
                        this.$el.scrollTop -= box.top + headerHeight - rowBox.top;
                    } else if (rowBox.bottom > box.bottom) {
                        this.$el.scrollTop += rowBox.bottom - box.bottom;
                    }
                });
            },
        }"
        x-init="
            scrollToBottom();
            new MutationObserver(() => { if (pinned) scrollToBottom(); }).observe($el, { childList: true, subtree: true });
            new ResizeObserver(() => { if (pinned) scrollToBottom(); }).observe($el);
        "
        x-effect="revealRecalled(recalledUuid, recalledContactId)"
        @scroll="pinned = $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 8"
        @contact-logged.window="$nextTick(() => scrollToBottom())"
    >
        {{-- Empty state: no server contacts and nothing pending client-side --}}
        @if($isEmpty)
            <div x-show="!({{ $hasPending }})" class="h-full flex items-center justify-center">
                <div class="text-center text-base-content/50 space-y-1">
                    <p>No contacts logged yet.</p>
                    <p class="text-xs">Type the other station's exchange, e.g. <span class="font-mono font-bold">{{ $example }}</span></p>
                </div>
            </div>
        @endif

        {{-- Mobile: card list --}}
        <div class="sm:hidden space-y-1.5 p-3" @if($isEmpty) x-show="{{ $hasPending }}" x-cloak @endif>
            {{ $cards }}
        </div>

        {{-- Desktop: table --}}
        <div class="hidden sm:block" @if($isEmpty) x-show="{{ $hasPending }}" x-cloak @endif>
            <table class="table table-sm table-pin-rows">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ $timeHeading }}</th>
                        <th>Callsign</th>
                        <th>Exchange</th>
                        <th>Section</th>
                        {{ $extraColumns ?? '' }}
                    </tr>
                </thead>
                <tbody>
                    {{ $rows }}
                </tbody>
            </table>
        </div>
    </div>

    {{ $slot }}
</div>
