// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Copy text to the clipboard. Resolves true on success, false on failure.
 *
 * navigator.clipboard only exists in secure contexts (HTTPS or localhost), and
 * most clubs reach the app over plain HTTP on the LAN. In that case fall back
 * to a hidden textarea and document.execCommand('copy').
 */
export async function copyToClipboard(text) {
    const value = String(text ?? '');

    if (globalThis.navigator?.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        } catch {
            // Permission denied or blocked: try the fallback below.
        }
    }

    return legacyCopy(value);
}

function legacyCopy(value) {
    const previous = document.activeElement;
    // Inside a modal <dialog> the rest of the page is inert, so attach there.
    const host = previous?.closest?.('dialog') ?? document.body;
    const area = document.createElement('textarea');

    area.value = value;
    area.setAttribute('readonly', '');
    area.setAttribute('aria-hidden', 'true');
    area.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;';
    host.appendChild(area);

    try {
        area.focus({ preventScroll: true });
        area.select();
        area.setSelectionRange(0, value.length);
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        area.remove();
        previous?.focus?.({ preventScroll: true });
    }
}
