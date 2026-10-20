{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
QSO Log Component

Chat-style log shared by live logging, paper-log transcription and the net
log: the history (oldest → newest) with the exchange composer docked
underneath.

The enclosing Alpine scope must provide recalledContactId and recalledUuid.

Visibility of the empty state and the card/table containers is pure CSS
(:has on the rendered rows), not x-show/x-cloak. Livewire's morph re-adds
x-cloak to elements Alpine has hidden, which kept the first queued contact
hidden until the server rendered it.
The page's sticky status bar should carry data-log-sticky-bar so phones can
size the log to the space left below it.

Props:
- $title: string - Card heading
- $subtitle: string|null - Muted text beside the heading
- $example: string - Example exchange shown in the empty state
- $timeHeading: string - Heading for the desktop time column
- $emptyTitle: string - Empty state heading
- $emptyHint: string|null - Empty state hint before the example

Slots:
- $cards: Mobile card list items
- $rows: Desktop table rows
- $extraColumns: Extra desktop column headings, after Section
- $headings: Desktop column headings, replacing the contact columns
- $slot: The docked composer
--}}

@props([
    'title',
    'subtitle' => null,
    'example' => 'W1AW 3A CT',
    'timeHeading' => 'Time',
    'emptyTitle' => 'No contacts logged yet.',
    'emptyHint' => "Type the other station's exchange, e.g.",
])

<div
    class="bg-base-100 sm:rounded-lg sm:shadow-sm border-y sm:border border-base-300 flex flex-col max-sm:h-[70dvh]"
    x-data="{
        aligning: true,
        {{-- Phones: size the log to fill the screen below the sticky header, weather alert and status bar,
             and on load scroll so the input sits at the bottom of the screen. --}}
        fitToViewport() {
            if (!window.matchMedia('(max-width: 639px)').matches) {
                this.$el.style.removeProperty('height');
                return;
            }
            const content = this.$el.parentElement;
            const stickyBarHeight = document.querySelector('[data-log-sticky-bar]')?.offsetHeight ?? 0;
            const headerHeight = document.querySelector('header.sticky')?.offsetHeight ?? 0;
            const alertBannerHeight = document.querySelector('[data-weather-alert-sticky]')?.offsetHeight ?? 0;
            const aboveCard = this.$el.getBoundingClientRect().top - content.getBoundingClientRect().top;
            const height = window.innerHeight - headerHeight - alertBannerHeight - stickyBarHeight - aboveCard;
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
        observer.observe(document.querySelector('[data-weather-alert-sticky]') ?? document.body);
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
        class="group/log max-sm:flex-1 max-sm:min-h-0 sm:h-80 overflow-y-auto overflow-x-auto"
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
        {{-- Empty state: shown until any row (server or queued) renders --}}
        <div class="h-full flex items-center justify-center group-has-[tbody_tr]/log:hidden">
            <div class="text-center text-base-content/50 space-y-1">
                <p>{{ $emptyTitle }}</p>
                <p class="text-xs">{{ $emptyHint }} <span class="font-mono font-bold">{{ $example }}</span></p>
            </div>
        </div>

        {{-- Mobile: card list --}}
        <div class="hidden max-sm:group-has-[tbody_tr]/log:block space-y-1.5 p-3">
            {{ $cards }}
        </div>

        {{-- Desktop: table --}}
        <div class="hidden sm:group-has-[tbody_tr]/log:block">
            <table class="table table-sm table-pin-rows">
                <thead>
                    <tr>
                        @isset($headings)
                            {{ $headings }}
                        @else
                            <th>#</th>
                            <th>{{ $timeHeading }}</th>
                            <th>Callsign</th>
                            <th>Exchange</th>
                            <th>Section</th>
                            {{ $extraColumns ?? '' }}
                        @endisset
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
