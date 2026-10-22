// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

import { formatAge } from './updated-ago';

/**
 * Station Last Heard - Alpine.js component for net control's station list.
 *
 * Keeps "last heard" ages and overdue flags current between renders, so a
 * station turns overdue on screen when its time runs out, not only when new
 * traffic is logged. Ages are measured on the server clock (data-server-now
 * on the root), so a station computer with a wrong clock still shows the
 * right age.
 *
 * Each station's times are ISO strings in data attributes (data-heard-at,
 * data-overdue-at), not in the Alpine expressions, because Alpine keeps the
 * expression it first saw while a Livewire morph updates attributes. A morph
 * also resets the x-show and :class markup to the server's, so every morph
 * re-ticks to bring them back in line straight away.
 */
export default function stationLastHeard() {
    return {
        now: Date.now(),
        intervalId: null,
        lastServerNow: null,
        serverClientOffset: 0,

        unhookMorph: null,

        init() {
            this.tick();
            this.intervalId = setInterval(() => this.tick(), 15000);
            this.unhookMorph = window.Livewire?.hook('morphed', ({ el }) => {
                if (el.contains(this.$root)) {
                    this.tick();
                }
            });
        },

        destroy() {
            if (this.intervalId) {
                clearInterval(this.intervalId);
            }

            this.unhookMorph?.();
        },

        tick() {
            const serverNow = this.$root.querySelector('[data-server-now]')?.dataset.serverNow;

            if (serverNow && serverNow !== this.lastServerNow) {
                this.lastServerNow = serverNow;
                this.serverClientOffset = Date.parse(serverNow) - Date.now();
            }

            this.now = Date.now() + this.serverClientOffset;
        },

        ago(heardAt) {
            if (!heardAt) {
                return 'Not heard yet';
            }

            return formatAge(Math.max(0, Math.floor((this.now - Date.parse(heardAt)) / 1000)));
        },

        isOverdue(overdueAt) {
            return Boolean(overdueAt) && this.now >= Date.parse(overdueAt);
        },

        overdueCount() {
            return [...this.$root.querySelectorAll('[data-overdue-at]')]
                .filter((el) => this.isOverdue(el.dataset.overdueAt))
                .length;
        },
    };
}
