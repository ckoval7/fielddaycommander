// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Roving focus - `x-roving-focus` Alpine directive for long lists of buttons.
 *
 * Makes every visible `[data-roving-item]` inside the element one Tab stop
 * between them, so keyboard users don't tab through the whole list to get
 * past it. Arrow Up/Down (and Left/Right) move between items, Home and End
 * jump to the first and last. The stop is the item last focused, or the
 * newest (last) item when that one is gone.
 *
 * Items come and go as Livewire morphs and x-for re-render, so the tab
 * stop is re-settled whenever the list changes.
 *
 * Usage in Blade: <div x-roving-focus> ... <button data-roving-item> ... </div>
 */
const KEYS_BACK = ['ArrowUp', 'ArrowLeft'];
const KEYS_FORWARD = ['ArrowDown', 'ArrowRight'];

function visibleItems(root) {
    return [...root.querySelectorAll('[data-roving-item]')].filter(item => item.offsetParent !== null);
}

export function rovingFocus(el, { cleanup }) {
    let current = null;

    const settle = () => {
        const items = visibleItems(el);
        if (!items.includes(current)) {
            current = items.at(-1) ?? null;
        }
        el.querySelectorAll('[data-roving-item]').forEach(item => {
            item.tabIndex = item === current ? 0 : -1;
        });
    };

    const onFocusIn = (event) => {
        const item = event.target.closest?.('[data-roving-item]');
        if (item && el.contains(item) && item !== current) {
            current = item;
            settle();
        }
    };

    const onKeyDown = (event) => {
        const item = event.target.closest?.('[data-roving-item]');
        if (!item || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const items = visibleItems(el);
        const index = items.indexOf(item);
        let next = null;

        if (KEYS_BACK.includes(event.key)) {
            next = items[Math.max(0, index - 1)];
        } else if (KEYS_FORWARD.includes(event.key)) {
            next = items[Math.min(items.length - 1, index + 1)];
        } else if (event.key === 'Home') {
            next = items[0];
        } else if (event.key === 'End') {
            next = items.at(-1);
        }

        if (next) {
            event.preventDefault();
            next.focus();
        }
    };

    // Settled in a frame so a burst of morph mutations costs one pass.
    let frame = null;
    const observer = new MutationObserver(() => {
        if (frame === null) {
            frame = requestAnimationFrame(() => {
                frame = null;
                settle();
            });
        }
    });
    const resizeObserver = new ResizeObserver(settle);

    el.addEventListener('focusin', onFocusIn);
    el.addEventListener('keydown', onKeyDown);
    observer.observe(el, { childList: true, subtree: true });
    resizeObserver.observe(el);
    settle();

    cleanup(() => {
        el.removeEventListener('focusin', onFocusIn);
        el.removeEventListener('keydown', onKeyDown);
        observer.disconnect();
        resizeObserver.disconnect();
        if (frame !== null) {
            cancelAnimationFrame(frame);
        }
    });
}
