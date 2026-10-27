// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

const UNITS = { d: 'day', h: 'hour', m: 'minute', s: 'second' };

/**
 * Spell out the d/h/m/s units of a short duration for screen readers, which
 * read "57m" as "57 meters": "2h 5m ago" becomes "2 hours 5 minutes ago".
 * Mirrors spokenDuration() in app/helpers.php.
 */
export function spokenDuration(duration) {
    return String(duration ?? '').replaceAll(/\b(\d+)([dhms])\b/g, (match, count, unit) => (
        `${count} ${UNITS[unit]}${Number(count) === 1 ? '' : 's'}`
    ));
}
