// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Speaks short messages to screen readers through the visually hidden live
 * regions in the layouts (#fdc-announce-polite and #fdc-announce-assertive).
 *
 * Call `announce('W1AW logged')` from JS, or dispatch a Livewire `announce`
 * event with `{ message, assertive }`. Toasts are announced automatically,
 * errors and warnings assertively.
 */

const CLEAR_DELAY_MS = 7000;
const timers = new WeakMap();

/**
 * Strips markup from toast titles and descriptions, which Mary renders as HTML.
 */
function plainText(value) {
    const element = document.createElement('div');
    element.innerHTML = value == null ? '' : String(value);

    return element.textContent.trim();
}

export function announce(message, { assertive = false } = {}) {
    const text = plainText(message);
    const region = document.getElementById(assertive ? 'fdc-announce-assertive' : 'fdc-announce-polite');

    if (!text || !region) {
        return;
    }

    // Clearing first and writing on the next tick makes screen readers
    // repeat a message that is identical to the previous one.
    clearTimeout(timers.get(region));
    region.textContent = '';
    setTimeout(() => {
        region.textContent = text;
        timers.set(region, setTimeout(() => {
            region.textContent = '';
        }, CLEAR_DELAY_MS));
    }, 50);
}

export function registerAnnouncer() {
    globalThis.fdcAnnounce = announce;

    globalThis.addEventListener('mary-toast', (event) => {
        const toast = event.detail?.toast ?? {};
        const urgent = /alert-(error|warning)/.test(toast.css ?? '');

        const parts = [plainText(toast.title), plainText(toast.description)].filter(Boolean);
        const message = parts.reduce((text, part) => (text === '' || /[.!?]$/.test(text) ? `${text} ${part}` : `${text}. ${part}`), '').trim();

        announce(message, { assertive: urgent });
    });

    document.addEventListener('livewire:init', () => {
        Livewire.on('announce', (payload) => {
            const data = (Array.isArray(payload) ? payload[0] : payload) || {};

            announce(data.message, { assertive: Boolean(data.assertive) });
        });
    });
}
