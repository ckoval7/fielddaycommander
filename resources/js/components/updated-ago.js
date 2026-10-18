// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Updated Ago - Alpine.js component for dashboard "Updated …" footers.
 *
 * Ticks client-side so the label keeps ageing when the widget stops
 * receiving fresh renders (lost connection, failed polls), instead of
 * freezing on whatever the server last said.
 *
 * Reads its timestamps from data attributes on every tick, because a
 * Livewire morph updates attributes but never re-runs x-data:
 *   data-updated-at  ISO timestamp the widget data was computed (server clock)
 *   data-server-now  ISO timestamp the server rendered this element
 *
 * The pair lets the age be measured on the server clock, so a TV with a
 * wrong system clock still reports the right age.
 *
 * Usage in Blade: <x-dashboard.updated-at :at="$data['last_updated_at']" />
 */
export default function updatedAgo() {
    return {
        label: '',
        intervalId: null,
        lastServerNow: null,
        serverClientOffset: 0,

        init() {
            this.tick();
            this.intervalId = setInterval(() => this.tick(), 5000);
        },

        destroy() {
            if (this.intervalId) {
                clearInterval(this.intervalId);
            }
        },

        tick() {
            const { updatedAt, serverNow } = this.$el.dataset;

            if (!updatedAt) {
                this.label = 'never';
                return;
            }

            if (serverNow && serverNow !== this.lastServerNow) {
                this.lastServerNow = serverNow;
                this.serverClientOffset = Date.parse(serverNow) - Date.now();
            }

            const ageSeconds = Math.max(0, Math.floor((Date.now() + this.serverClientOffset - Date.parse(updatedAt)) / 1000));
            this.label = formatAge(ageSeconds);
        },
    };
}

function formatAge(seconds) {
    if (seconds < 60) {
        return 'just now';
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ago`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h ago`;
    }

    return `${Math.floor(seconds / 86400)}d ago`;
}
