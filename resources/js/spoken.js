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

const PHONETICS = {
    A: 'Alpha', B: 'Bravo', C: 'Charlie', D: 'Delta', E: 'Echo', F: 'Foxtrot',
    G: 'Golf', H: 'Hotel', I: 'India', J: 'Juliet', K: 'Kilo', L: 'Lima',
    M: 'Mike', N: 'November', O: 'Oscar', P: 'Papa', Q: 'Quebec', R: 'Romeo',
    S: 'Sierra', T: 'Tango', U: 'Uniform', V: 'Victor', W: 'Whiskey', X: 'X-ray',
    Y: 'Yankee', Z: 'Zulu', 0: 'Zero', 1: 'One', 2: 'Two', 3: 'Three',
    4: 'Four', 5: 'Five', 6: 'Six', 7: 'Seven', 8: 'Eight', 9: 'Nine',
};

/**
 * Spell callsigns phonetically, since screen readers say "W1AW" as "W 1 ah".
 * Mirrors spokenCallsigns() in app/helpers.php.
 */
export function spokenCallsigns(text) {
    return String(text ?? '').replaceAll(/\b(?:[A-Z]{1,2}|\d[A-Z])\d[A-Z]{1,4}\b/g, (callsign) => (
        [...callsign].map((char) => PHONETICS[char] ?? char).join(' ')
    ));
}

/**
 * Expand short forms screen readers misread. Mirrors spokenAbbreviations()
 * in app/helpers.php.
 */
export function spokenAbbreviations(text) {
    return spokenCallsigns(String(text ?? '')
        .replaceAll(/\bPts\b/gi, 'points')
        .replaceAll(/\bPt\b/gi, 'point')
        .replaceAll(/\bMulti?\.(?=\s|$)/gi, 'multiplier')
        .replaceAll(/\bN\/A\b/g, 'not available')
        .replaceAll(/\/hr\b/g, ' per hour')
        .replaceAll(/\bQs\b/g, 'contacts')
        .replaceAll(/\bWFD\b/g, 'W F D'));
}
