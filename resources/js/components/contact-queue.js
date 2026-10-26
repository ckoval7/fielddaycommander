// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Contact Queue - Alpine.js store-and-forward component for contact logging.
 *
 * Manages a localStorage-backed queue of contacts. Contacts are queued locally
 * and synced to the server via the /logging/contacts endpoint. Handles
 * offline detection, retry with exponential backoff, and sync status display.
 *
 * Usage in Blade:
 *   <div x-data="contactQueue(sessionId, csrfToken)">
 */
/** Where the Advanced Fields panel's open state is kept on this device. */
const ADVANCED_OPEN_KEY = 'fd-commander-advanced-open';

/** Where a half-typed contact waits while a band/mode switch loads the new session. */
const SWITCH_DRAFT_KEY = 'fd-commander-switch-draft';

/** RST (59, 599) or a digital-mode dB report (-10, +05). Matches AdvancedContactFields. */
const RST_PATTERN = /^([1-5][1-9][1-9]?|[+-]\d{1,2})$/;

/** A 4- or 6-character Maidenhead locator. */
const GRID_PATTERN = /^[A-R]{2}\d{2}([A-X]{2})?$/i;

/** Words that switch mode when typed alone in the exchange box, by mode category. */
const MODE_COMMANDS = {
    CW: 'CW',
    PH: 'Phone',
    PHONE: 'Phone',
    SSB: 'Phone',
    VOICE: 'Phone',
    DIG: 'Digital',
    DIGI: 'Digital',
    DIGITAL: 'Digital',
    DATA: 'Digital',
};

function emptyAdvancedFields(frequency = '') {
    return { frequency, rst_sent: '', rst_received: '', grid_square: '', contact_name: '', notes: '' };
}

function readStorage(storage, key) {
    try {
        return storage.getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(storage, key, value) {
    try {
        if (value === null) {
            storage.removeItem(key);
        } else {
            storage.setItem(key, value);
        }
    } catch {
        // Private windows and blocked storage: the feature still works for this page.
    }
}

export default function contactQueue(sessionId, csrfToken, sessionContext) {
    return {
        queue: [],
        isOnline: navigator.onLine,
        syncIntervalId: null,
        storageKey: `fd-commander-queue-${sessionId}`,
        // True while the last attempt to save the queue to localStorage failed.
        storageFailing: false,
        recallIndex: -1,
        recalledContactId: null,
        recalledUuid: null,
        recalledExchange: '',
        pendingIndicatorDelayMs: 1000,
        syncedFallbackMs: 10000,
        now: Date.now(),
        unhookMorphed: null,
        // Server clock minus this device's clock, in ms. QSO times are stamped
        // on the server's clock (which follows Developer Tools' fake clock),
        // so a device whose clock is wrong still logs the right time.
        clockOffsetMs: Number.isFinite(sessionContext?.server_time_ms)
            ? sessionContext.server_time_ms - Date.now()
            : 0,
        // Advanced Fields. Frequency stays set between contacts; the rest
        // clear once a contact is queued.
        advanced: emptyAdvancedFields(),
        advancedOpen: readStorage(localStorage, ADVANCED_OPEN_KEY) === '1',
        // The fields as they were before a recall loaded a contact's own.
        advancedBeforeRecall: null,
        // {band_id, name} when a dial frequency is on another band.
        offBandSuggestion: null,
        switching: false,
        switchError: '',

        init() {
            this.loadQueue();
            this.restoreSwitchDraft();

            globalThis.addEventListener('online', () => {
                this.isOnline = true;
                void this.syncNext();
            });
            globalThis.addEventListener('offline', () => {
                this.isOnline = false;
            });

            this.syncIntervalId = setInterval(() => this.syncNext(), 3000);

            // Drop queued copies once the server-rendered row for the same
            // contact lands, so the log never shows both or neither.
            this.unhookMorphed = globalThis.Livewire?.hook('morphed', () => this.pruneConfirmed()) ?? null;
        },

        destroy() {
            if (this.syncIntervalId) {
                clearInterval(this.syncIntervalId);
            }
            this.unhookMorphed?.();
        },

        /**
         * Remove queue entries whose contact the server has already rendered.
         * Failed and in-edit entries stay so the operator can act on them.
         */
        pruneConfirmed() {
            const rendered = new Set(
                [...document.querySelectorAll('[data-contact-uuid]')].map(el => el.dataset.contactUuid)
            );
            const remaining = this.queue.filter(c =>
                !rendered.has(c.uuid) || c.status === 'failed' || c.status === 'editing'
            );
            if (remaining.length !== this.queue.length) {
                this.queue = remaining;
                this.saveQueue();
            }
        },

        get pendingContacts() {
            return this.queue.filter(c => c.status === 'pending' || c.status === 'syncing' || c.status === 'editing');
        },

        get failedContacts() {
            return this.queue.filter(c => c.status === 'failed');
        },

        get pendingCount() {
            return this.pendingContacts.length;
        },

        /**
         * Contacts that have been waiting long enough to surface a pending
         * indicator. Hides the momentary flash on a fast connection.
         */
        get visiblePendingCount() {
            return this.pendingContacts.filter(c => this.isPendingIndicatorVisible(c)).length;
        },

        isPendingIndicatorVisible(contact) {
            if (contact.status !== 'pending' && contact.status !== 'syncing') {
                return false;
            }
            return this.now - (contact.queued_at || 0) >= this.pendingIndicatorDelayMs;
        },

        /**
         * Re-check pending indicators once the delay elapses for a freshly queued contact.
         */
        schedulePendingIndicator(entry) {
            entry.queued_at = Date.now();
            this.now = entry.queued_at;
            setTimeout(() => {
                this.now = Date.now();
            }, this.pendingIndicatorDelayMs);
        },

        /**
         * Warn while the queue can't be saved on this device and contacts are
         * still waiting, so a reload or closed tab would lose them.
         */
        get storageWarningVisible() {
            return this.storageFailing
                && this.queue.some(c => c.status !== 'synced');
        },

        /**
         * Queued contacts that belong to this session. Contacts carried over
         * from a band/mode switch count toward the session they were made in.
         */
        get sessionQueueCount() {
            return this.queue.filter(c => c.operating_session_id === sessionId).length;
        },

        get failedCount() {
            return this.failedContacts.length;
        },

        get syncStatus() {
            if (!this.isOnline) {
                return 'offline';
            }
            if (this.failedCount > 0) {
                return 'failed';
            }
            if (this.visiblePendingCount > 0) {
                return 'syncing';
            }
            return 'synced';
        },

        get statusLabel() {
            switch (this.syncStatus) {
                case 'offline':
                    return `Offline · ${this.pendingCount + this.failedCount} queued`;
                case 'failed':
                    return `${this.failedCount} failed`;
                case 'syncing':
                    return `${this.pendingCount} pending`;
                default:
                    return '';
            }
        },

        get statusDotClass() {
            switch (this.syncStatus) {
                case 'offline':
                    return 'bg-error';
                case 'failed':
                    return 'bg-error';
                case 'syncing':
                    return 'bg-warning';
                default:
                    return 'bg-success';
            }
        },

        enqueue(contactData) {
            const entry = {
                uuid: crypto.randomUUID?.() ?? ([1e7]+-1e3+-4e3+-8e3+-1e11).toString().replaceAll(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)),
                operating_session_id: sessionId,
                band_id: contactData.band_id,
                mode_id: contactData.mode_id,
                submode: contactData.submode || null,
                callsign: contactData.callsign,
                section_id: contactData.section_id,
                section_code: contactData.section_code || '',
                exchange_class: contactData.exchange_class,
                power_watts: contactData.power_watts,
                is_gota_contact: contactData.is_gota_contact || false,
                gota_operator_first_name: contactData.gota_operator_first_name || null,
                gota_operator_last_name: contactData.gota_operator_last_name || null,
                gota_operator_callsign: contactData.gota_operator_callsign || null,
                gota_operator_user_id: contactData.gota_operator_user_id || null,
                ...this.emptyToNull(contactData.advanced ?? {}),
                qso_time: new Date(this.serverNow()).toISOString(),
                clock_offset_ms: this.clockOffsetMs,
                status: 'pending',
                attempts: 0,
                last_error: null,
            };

            this.schedulePendingIndicator(entry);
            this.queue.unshift(entry);
            this.saveQueue();
            void this.syncNext();
        },

        /**
         * Parse exchange client-side and queue to localStorage immediately.
         * No server round-trip — sync happens in the background.
         */
        logContact(inputEl) {
            const rawInput = (inputEl?.value || '').trim();
            if (!rawInput) {
                this.parseError = 'Enter callsign, class, and section';
                return;
            }

            const command = this.parseSwitchCommand(rawInput);
            if (command) {
                void this.switchBandMode(command.band_id, command.mode_id, { keepDraft: false });
                return;
            }

            if (sessionContext.is_gota && !this.hasGotaOperator()) {
                this.parseError = 'Enter the GOTA operator in the GOTA Operator panel before logging.';
                return;
            }

            const parsed = this.parseExchange(rawInput);
            if (!parsed) return; // parseError already set

            const advanced = this.parseAdvancedFields();
            if (!advanced) return; // parseError already set

            this.enqueue({
                band_id: sessionContext.band_id,
                mode_id: sessionContext.mode_id,
                submode: sessionContext.submode || null,
                callsign: parsed.callsign,
                section_id: parsed.section_id,
                section_code: parsed.section_code,
                exchange_class: (parsed.transmitter_count + parsed.class_code).toUpperCase(),
                power_watts: sessionContext.power_watts,
                is_gota_contact: sessionContext.is_gota || false,
                gota_operator_first_name: sessionContext.gota_operator_first_name || null,
                gota_operator_last_name: sessionContext.gota_operator_last_name || null,
                gota_operator_callsign: sessionContext.gota_operator_callsign || null,
                gota_operator_user_id: sessionContext.gota_operator_user_id || null,
                advanced,
            });

            // Clear input and refocus
            inputEl.value = '';
            this.parseError = '';
            this.advanced = emptyAdvancedFields(this.advanced.frequency);
            // Sync Livewire state so wire:model.live stays in sync
            const wire = globalThis.Livewire?.find(inputEl.closest(String.raw`[wire\:id]`)?.getAttribute('wire:id'));
            if (wire) {
                try {
                    wire.set('exchangeInput', '');
                } catch (e) {
                    // Silently ignored - Livewire state sync is best-effort; input is already cleared in the DOM
                    console.warn('Failed to sync Livewire exchangeInput state:', e);
                }
            }
            inputEl.focus();
        },

        parseError: '',

        /**
         * GOTA contacts are credited to the GOTA operator: a linked user, or a first and last name.
         */
        hasGotaOperator() {
            const firstName = (sessionContext.gota_operator_first_name || '').trim();
            const lastName = (sessionContext.gota_operator_last_name || '').trim();
            return Boolean(sessionContext.gota_operator_user_id) || (firstName !== '' && lastName !== '');
        },

        /**
         * Client-side exchange parser.
         * Format: CALLSIGN CLASS SECTION (e.g. "W1AW 3A CT")
         */
        parseExchange(input) {
            const tokens = input.toUpperCase().trim().split(/\s+/);

            if (tokens.length < 3) {
                this.parseError = 'Enter callsign, class, and section (e.g. W1AW 3A CT)';
                return null;
            }
            if (tokens.length > 3) {
                this.parseError = 'Use only callsign, class, and section';
                return null;
            }

            const callsign = tokens[0];
            if (callsign.length < 3 || callsign.length > 10 ||
                !/\d/.test(callsign) || !/[A-Z]/.test(callsign) ||
                !/^[A-Z0-9/]+$/.test(callsign)) {
                this.parseError = `Invalid callsign: ${callsign}`;
                return null;
            }

            if (!/^\d{1,2}[A-FHIMO]$/i.test(tokens[1])) {
                this.parseError = `Invalid class: ${tokens[1]} (use a number and class letter, e.g. 3A or 2M)`;
                return null;
            }

            // Only the class letters this event's rulebook defines are legal,
            // so a Field Day log rejects Winter Field Day classes and vice
            // versa. Falls through when the server sent no list.
            const validClasses = sessionContext.class_codes;
            const letter = tokens[1].slice(-1).toUpperCase();
            if (Array.isArray(validClasses) && validClasses.length > 0 && !validClasses.includes(letter)) {
                this.parseError = `Class ${letter} is not valid for this event`;
                return null;
            }

            const sectionCode = tokens[2];
            const sectionId = sessionContext.sections?.[sectionCode];
            if (!sectionId) {
                this.parseError = `Unknown section: ${sectionCode}`;
                return null;
            }

            const classMatch = tokens[1].match(/^(\d{1,2})([A-FHIMO])$/i);
            const transmitter_count = classMatch[1];
            const class_code = classMatch[2].toUpperCase();

            this.parseError = '';
            return { callsign, section_id: sectionId, section_code: sectionCode, transmitter_count, class_code };
        },

        /**
         * Check and convert the Advanced Fields for queuing, or return null
         * with parseError set. A frequency on another band offers a switch.
         */
        parseAdvancedFields() {
            this.offBandSuggestion = null;
            const fields = this.advanced;
            const result = {};

            const frequency = String(fields.frequency ?? '').trim();
            if (frequency !== '') {
                const mhz = Number(frequency.replace(',', '.'));
                if (!Number.isFinite(mhz) || mhz <= 0) {
                    this.parseError = `Invalid frequency: ${frequency} (enter MHz, e.g. 14.074)`;
                    return null;
                }
                const bandId = this.bandIdForMhz(mhz);
                const range = sessionContext.band_ranges?.[sessionContext.band_id];
                if (range && bandId !== sessionContext.band_id) {
                    if (bandId) {
                        this.offBandSuggestion = { band_id: bandId, name: sessionContext.band_ranges[bandId].name };
                        this.parseError = `${frequency} MHz is on ${this.offBandSuggestion.name}, not ${range.name}.`;
                    } else {
                        this.parseError = `${frequency} MHz is not on ${range.name}.`;
                    }
                    return null;
                }
                result.frequency_hz = Math.round(mhz * 1_000_000);
            }

            for (const [field, label] of [['rst_sent', 'sent'], ['rst_received', 'received']]) {
                const value = String(fields[field] ?? '').trim().toUpperCase();
                if (value !== '' && !RST_PATTERN.test(value)) {
                    this.parseError = `Invalid ${label} report: ${value} (e.g. 59, 599, or -10)`;
                    return null;
                }
                result[field] = value;
            }

            const grid = String(fields.grid_square ?? '').trim();
            if (grid !== '' && !GRID_PATTERN.test(grid)) {
                this.parseError = `Invalid grid square: ${grid} (e.g. FN31 or FN31pr)`;
                return null;
            }
            result.grid_square = grid === '' ? '' : grid.slice(0, 4).toUpperCase() + grid.slice(4).toLowerCase();
            result.contact_name = String(fields.contact_name ?? '').trim().slice(0, 50);
            result.notes = String(fields.notes ?? '').trim().slice(0, 500);

            return result;
        },

        /**
         * The band a frequency in MHz falls on, or null.
         */
        bandIdForMhz(mhz) {
            for (const [id, range] of Object.entries(sessionContext.band_ranges ?? {})) {
                if (mhz >= range.low && mhz <= range.high) {
                    return Number(id);
                }
            }
            return null;
        },

        /**
         * Blank strings as null, so the server stores nothing rather than ''.
         */
        emptyToNull(fields) {
            return Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, value === '' ? null : value]));
        },

        get frequencyPlaceholder() {
            return String(sessionContext.band_ranges?.[sessionContext.band_id]?.low ?? '');
        },

        get reportPlaceholder() {
            return { CW: '599', Phone: '59', Digital: '-10' }[sessionContext.mode_category] ?? '59';
        },

        /** Digital reports need a minus sign, which phone number pads don't have. */
        get reportInputMode() {
            return sessionContext.mode_category === 'Digital' ? 'text' : 'numeric';
        },

        /**
         * Clear what was typed for this contact. The frequency stays.
         */
        clearAdvanced() {
            this.advanced = emptyAdvancedFields(this.advanced.frequency);
            this.offBandSuggestion = null;
        },

        toggleAdvanced(focusFirst = false) {
            this.advancedOpen = !this.advancedOpen;
            writeStorage(localStorage, ADVANCED_OPEN_KEY, this.advancedOpen ? '1' : '0');
            if (this.advancedOpen && focusFirst) {
                // After x-show has displayed the panel; a hidden field can't take focus.
                this.$nextTick(() => requestAnimationFrame(() => document.getElementById('log-frequency')?.focus()));
            } else if (!this.advancedOpen) {
                document.getElementById('exchange-input')?.focus();
            }
        },

        /**
         * A band and/or mode typed alone in the exchange box ("40M", "40",
         * "CW", "20M CW"), as the session it switches to, or null when the
         * input is anything else.
         */
        parseSwitchCommand(input) {
            const tokens = input.toUpperCase().trim().split(/\s+/);
            if (tokens.length === 0 || tokens.length > 2) {
                return null;
            }

            let bandId = null;
            let modeId = null;
            for (const token of tokens) {
                const band = (sessionContext.bands ?? []).find(b =>
                    b.name.toUpperCase() === token || b.name.toUpperCase() === `${token}M`
                );
                const category = MODE_COMMANDS[token];
                const mode = category ? (sessionContext.modes ?? []).find(m => m.category === category) : null;

                if (band && bandId === null) {
                    bandId = band.id;
                } else if (mode && modeId === null) {
                    modeId = mode.id;
                } else {
                    return null;
                }
            }

            return {
                band_id: bandId ?? sessionContext.band_id,
                mode_id: modeId ?? sessionContext.mode_id,
            };
        },

        /**
         * End this session and continue on another band and mode. Contacts
         * still waiting to sync move to the new session's queue (they keep
         * their own session, band and mode), the frequency comes along when
         * it's on the new band, and with keepDraft so does the half-typed
         * contact.
         */
        async switchBandMode(bandId, modeId, { keepDraft = true } = {}) {
            bandId = Number(bandId);
            modeId = Number(modeId);
            this.switchError = '';

            if (this.switching || (bandId === sessionContext.band_id && modeId === sessionContext.mode_id)) {
                return;
            }
            if (!navigator.onLine) {
                this.switchError = 'You are offline. Reconnect to change band or mode.';
                return;
            }

            this.switching = true;
            let result;
            try {
                result = await this.$wire.switchBandMode(bandId, modeId);
            } catch (e) {
                console.warn('Band/mode switch failed:', e);
                result = null;
            }

            if (!result?.ok) {
                this.switching = false;
                this.switchError = result?.message ?? 'Band and mode could not be changed. Try again.';
                return;
            }

            this.carryQueueTo(result.session_id);

            // The frequency rides along when it's on the new band. With
            // keepDraft, so does the contact being typed.
            const input = document.getElementById('exchange-input');
            const range = sessionContext.band_ranges?.[bandId];
            const mhz = Number(String(this.advanced.frequency ?? '').replace(',', '.'));
            const keepFrequency = range && Number.isFinite(mhz) && mhz >= range.low && mhz <= range.high;
            writeStorage(sessionStorage, SWITCH_DRAFT_KEY, JSON.stringify({
                session_id: result.session_id,
                exchange: keepDraft ? (input?.value ?? '') : '',
                advanced: {
                    ...(keepDraft ? this.advanced : emptyAdvancedFields()),
                    frequency: keepFrequency ? this.advanced.frequency : '',
                },
            }));

            if (globalThis.Livewire?.navigate) {
                globalThis.Livewire.navigate(result.url);
            } else {
                globalThis.location.assign(result.url);
            }
        },

        /**
         * Move every unsynced contact into another session's stored queue.
         */
        carryQueueTo(newSessionId) {
            const waiting = this.queue.filter(c => c.status !== 'synced');
            if (waiting.length === 0) {
                return;
            }
            const key = `fd-commander-queue-${newSessionId}`;
            let existing = [];
            try {
                existing = JSON.parse(readStorage(localStorage, key) ?? '[]');
            } catch {
                existing = [];
            }
            const known = new Set(existing.map(c => c.uuid));
            writeStorage(localStorage, key, JSON.stringify([...waiting.filter(c => !known.has(c.uuid)), ...existing]));
            this.queue = [];
            writeStorage(localStorage, this.storageKey, null);
        },

        /**
         * Refill the composer with what was being typed when the band or
         * mode was switched.
         */
        restoreSwitchDraft() {
            const raw = readStorage(sessionStorage, SWITCH_DRAFT_KEY);
            if (!raw) {
                return;
            }
            writeStorage(sessionStorage, SWITCH_DRAFT_KEY, null);
            try {
                const draft = JSON.parse(raw);
                if (draft.session_id !== sessionId) {
                    return;
                }
                this.advanced = { ...emptyAdvancedFields(), ...draft.advanced };
                if (draft.exchange) {
                    this.$nextTick(() => {
                        const input = document.getElementById('exchange-input');
                        if (input) {
                            input.value = draft.exchange;
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                            input.focus();
                        }
                    });
                }
            } catch {
                // A malformed draft is dropped; the operator retypes.
            }
        },

        /**
         * Current time on the server's clock, in epoch ms.
         */
        serverNow() {
            return Date.now() + this.clockOffsetMs;
        },

        /**
         * Update the clock offset from a sync response's X-Server-Time header,
         * allowing half the round trip for the response to arrive.
         */
        measureClockOffset(response, sentAt) {
            const serverTime = Number(response.headers?.get?.('X-Server-Time'));
            if (!Number.isFinite(serverTime) || serverTime <= 0) {
                return;
            }
            const receivedAt = Date.now();
            this.clockOffsetMs = Math.round(serverTime + (receivedAt - sentAt) / 2 - receivedAt);
        },

        /**
         * Shift a queued contact's time by how much the clock offset has
         * changed since it was stamped, so a contact stamped with a wrong (or
         * no) offset is re-dated on the server's clock.
         */
        restamp(entry) {
            const delta = this.clockOffsetMs - (entry.clock_offset_ms ?? 0);
            entry.qso_time = new Date(Date.parse(entry.qso_time) + delta).toISOString();
            entry.clock_offset_ms = this.clockOffsetMs;
        },

        isFutureTimeError(entry) {
            return entry.last_error_code === 'qso_time_in_future'
                || (entry.last_error ?? '').includes('dated in the future');
        },

        async syncNext() {
            if (!this.isOnline) {
                return;
            }

            const candidate = this.queue.find(c => {
                if (c.status === 'pending') {
                    return true;
                }
                if (c.status === 'failed' && c.attempts < 10) {
                    const backoff = Math.min(3000 * Math.pow(2, c.attempts), 60000);
                    const elapsed = Date.now() - (c.lastAttemptTime || 0);
                    return elapsed >= backoff;
                }
                return false;
            });

            if (!candidate) {
                return;
            }

            candidate.status = 'syncing';
            this.saveQueue();

            const sentAt = Date.now();

            try {
                const response = await fetch('/logging/contacts', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || csrfToken,
                    },
                    body: JSON.stringify({
                        uuid: candidate.uuid,
                        operating_session_id: candidate.operating_session_id,
                        band_id: candidate.band_id,
                        mode_id: candidate.mode_id,
                        submode: candidate.submode || null,
                        callsign: candidate.callsign,
                        section_id: candidate.section_id,
                        exchange_class: candidate.exchange_class,
                        power_watts: candidate.power_watts,
                        qso_time: candidate.qso_time,
                        is_gota_contact: candidate.is_gota_contact || false,
                        gota_operator_first_name: candidate.gota_operator_first_name || null,
                        gota_operator_last_name: candidate.gota_operator_last_name || null,
                        gota_operator_callsign: candidate.gota_operator_callsign || null,
                        gota_operator_user_id: candidate.gota_operator_user_id || null,
                        frequency_hz: candidate.frequency_hz ?? null,
                        rst_sent: candidate.rst_sent ?? null,
                        rst_received: candidate.rst_received ?? null,
                        grid_square: candidate.grid_square ?? null,
                        contact_name: candidate.contact_name ?? null,
                        notes: candidate.notes ?? null,
                    }),
                });

                this.measureClockOffset(response, sentAt);

                if (response.ok) {
                    await response.json();

                    // Keep the entry on screen as 'synced' until the refreshed
                    // server row replaces it (see pruneConfirmed), so the log
                    // doesn't drop a line and then re-add it.
                    const synced = this.queue.find(c => c.uuid === candidate.uuid);
                    if (synced) {
                        synced.status = 'synced';
                        this.saveQueue();
                        setTimeout(() => {
                            this.queue = this.queue.filter(c => !(c.uuid === candidate.uuid && c.status === 'synced'));
                            this.saveQueue();
                        }, this.syncedFallbackMs);
                    }

                    // Notify the Livewire component to refresh the recent
                    // contacts list. Uses Livewire's event dispatch which is
                    // reliable after wire:navigate SPA transitions.
                    globalThis.Livewire?.dispatch('contact-synced');
                } else if (response.status === 422) {
                    const errorData = await response.json();
                    if (errorData.code === 'qso_time_in_future' && !candidate.restamped) {
                        // Stamped with a wrong clock offset (or before offsets
                        // were measured): re-date it on the server clock once
                        // and send it again.
                        if (Number.isFinite(errorData.server_time)) {
                            this.clockOffsetMs = errorData.server_time - Date.now();
                        }
                        this.restamp(candidate);
                        candidate.restamped = true;
                        candidate.status = 'pending';
                        this.saveQueue();
                        return;
                    }
                    candidate.status = 'failed';
                    candidate.attempts++;
                    candidate.lastAttemptTime = Date.now();
                    candidate.last_error = errorData.message || 'Contact could not be synced. Check its details.';
                    candidate.last_error_code = errorData.code ?? null;
                    this.saveQueue();
                } else if (response.status === 401 || response.status === 419) {
                    // Session or CSRF expired - stop retrying, user must refresh
                    candidate.status = 'failed';
                    candidate.attempts = 999;
                    candidate.last_error = 'Session expired. Please refresh the page, then retry.';
                    this.saveQueue();
                } else {
                    // Transient server error - retry
                    candidate.status = 'pending';
                    candidate.attempts++;
                    candidate.lastAttemptTime = Date.now();
                    this.saveQueue();
                }
            } catch (e) {
                // Network error (fetch threw) - queue contact for retry with backoff
                console.warn('Contact sync failed due to network error; will retry:', e);
                candidate.status = 'pending';
                candidate.attempts++;
                candidate.lastAttemptTime = Date.now();
                this.saveQueue();
            }
        },

        retryFailed(uuid) {
            const contact = this.queue.find(c => c.uuid === uuid);
            if (contact) {
                if (this.isFutureTimeError(contact)) {
                    this.restamp(contact);
                }
                contact.status = 'pending';
                contact.attempts = 0;
                contact.restamped = false;
                contact.last_error = null;
                contact.last_error_code = null;
                this.saveQueue();
                void this.syncNext();
            }
        },

        discardFailed(uuid) {
            this.queue = this.queue.filter(c => c.uuid !== uuid);
            this.saveQueue();
            globalThis.Livewire?.dispatch('contact-discarded');
        },

        get isRecalling() {
            return this.recallIndex >= 0;
        },

        get recallableContacts() {
            const contacts = [];

            // Queue items first — newest first (recall walks back in time).
            // Skip entries whose fetch is in flight; the response handler will
            // mutate them and race with the user's edit. The currently-recalled
            // entry (status === 'editing') stays in the list so navigation
            // indices remain stable.
            for (const entry of this.queue) {
                if (entry.status === 'syncing' || entry.status === 'synced') continue;
                contacts.push({
                    source: 'queue',
                    uuid: entry.uuid,
                    id: null,
                    callsign: entry.callsign,
                    exchangeClass: entry.exchange_class,
                    section: entry.section_code || '',
                    fullExchange: `${entry.callsign} ${entry.exchange_class} ${entry.section_code || ''}`.trim(),
                    advanced: this.advancedFromStored(entry),
                });
            }

            // Server-confirmed contacts from the DOM table rows. The desktop
            // table renders oldest → newest, so walk it backwards.
            const rows = [...document.querySelectorAll(String.raw`tr[wire\:key^="contact-"]`)].reverse();
            rows.forEach(row => {
                // Skip deleted rows (they have line-through class)
                if (row.classList.contains('line-through')) return;
                const callsignCell = row.querySelector('td:nth-child(3)');
                const exchangeClassCell = row.querySelector('td:nth-child(4)');
                const sectionCell = row.querySelector('td:nth-child(5)');
                const wireKey = row.getAttribute('wire:key');
                const contactId = wireKey ? Number.parseInt(wireKey.replace('contact-', '')) : null;
                if (contactId && callsignCell && exchangeClassCell && sectionCell) {
                    const callsign = callsignCell.textContent.trim().split('\n')[0].trim();
                    const exchangeClass = exchangeClassCell.textContent.trim();
                    const section = sectionCell.textContent.trim();
                    contacts.push({
                        source: 'server',
                        uuid: null,
                        id: contactId,
                        callsign,
                        exchangeClass,
                        section,
                        fullExchange: `${callsign} ${exchangeClass} ${section}`,
                        advanced: this.advancedFromStored(JSON.parse(row.dataset.advanced || '{}')),
                    });
                }
            });

            return contacts;
        },

        recallUp(inputEl) {
            const contacts = this.recallableContacts;
            if (contacts.length === 0) return;

            if (this.recallIndex < contacts.length - 1) {
                this.recallIndex++;
            }

            const contact = contacts[this.recallIndex];
            if (contact) {
                this._applyRecallTarget(contact, inputEl);
            }
        },

        recallDown(inputEl) {
            if (this.recallIndex <= 0) {
                this.exitRecall(inputEl);
                return;
            }

            this.recallIndex--;
            const contacts = this.recallableContacts;
            const contact = contacts[this.recallIndex];
            if (contact) {
                this._applyRecallTarget(contact, inputEl);
            }
        },

        /**
         * Tap-to-recall a queued contact by its client-side uuid.
         * No-op if the entry is mid-sync or not found.
         */
        recallByUuid(uuid) {
            const input = document.getElementById('exchange-input');
            if (!input) return;
            const all = this.recallableContacts;
            const idx = all.findIndex(c => c.source === 'queue' && c.uuid === uuid);
            if (idx < 0) return;
            this.recallIndex = idx;
            this._applyRecallTarget(all[idx], input);
            input.focus();
        },

        /**
         * Tap-to-recall a server-confirmed contact by its id.
         * Assumes the contact is present in the DOM's recent-QSO table.
         */
        recallByContactId(id) {
            const input = document.getElementById('exchange-input');
            if (!input) return;
            const all = this.recallableContacts;
            const idx = all.findIndex(c => c.source === 'server' && c.id === id);
            if (idx < 0) return;
            this.recallIndex = idx;
            this._applyRecallTarget(all[idx], input);
            input.focus();
        },

        /**
         * Move the recall pointer to a target contact. Releases any sync lock
         * on the previously recalled queue entry and, if the new target is a
         * queue entry, locks it with status='editing' so syncNext skips it.
         */
        _applyRecallTarget(contact, inputEl) {
            // Release lock on whatever queue entry was previously recalled (if any).
            this._releaseRecallLock();

            inputEl.value = contact.fullExchange;
            this.recalledExchange = contact.fullExchange;
            this.advancedBeforeRecall ??= { ...this.advanced };
            this.advanced = contact.advanced ?? emptyAdvancedFields();

            if (contact.source === 'queue') {
                this.recalledUuid = contact.uuid;
                this.recalledContactId = null;
                const entry = this.queue.find(c => c.uuid === contact.uuid);
                if (entry && entry.status !== 'editing') {
                    entry._prevStatus = entry.status;
                    entry.status = 'editing';
                    this.saveQueue();
                }
            } else {
                this.recalledContactId = contact.id;
                this.recalledUuid = null;
            }
        },

        /**
         * Restore the prior status of the currently recalled queue entry (if any).
         * Called before moving to a new recall target and from exitRecall.
         */
        _releaseRecallLock() {
            if (!this.recalledUuid) return;
            const entry = this.queue.find(c => c.uuid === this.recalledUuid);
            if (entry?.status === 'editing') {
                entry.status = entry._prevStatus ?? 'pending';
                delete entry._prevStatus;
                this.saveQueue();
            }
        },

        exitRecall(inputEl) {
            this._releaseRecallLock();
            this.recallIndex = -1;
            this.recalledContactId = null;
            this.recalledUuid = null;
            this.recalledExchange = '';
            if (this.advancedBeforeRecall) {
                this.advanced = this.advancedBeforeRecall;
                this.advancedBeforeRecall = null;
            }
            if (inputEl) {
                inputEl.value = '';
                const wire = globalThis.Livewire?.find(inputEl.closest(String.raw`[wire\:id]`)?.getAttribute('wire:id'));
                if (wire) {
                    try { wire.set('exchangeInput', ''); } catch { /* best-effort sync */ }
                }
                inputEl.focus();
            }
        },

        deleteRecalled(inputEl) {
            if (!this.isRecalling) return;

            // Queue-sourced delete: drop the entry from the queue.
            if (this.recalledUuid) {
                const uuid = this.recalledUuid;
                // Clear the ref before exitRecall so _releaseRecallLock is a no-op.
                this.recalledUuid = null;
                this.queue = this.queue.filter(c => c.uuid !== uuid);
                this.saveQueue();
                globalThis.Livewire?.dispatch('contact-discarded');
                this.exitRecall(inputEl);
                return;
            }

            // Server-sourced delete: delegate to Livewire.
            if (this.recalledContactId) {
                const contactId = this.recalledContactId;
                const wire = globalThis.Livewire?.find(inputEl.closest(String.raw`[wire\:id]`)?.getAttribute('wire:id'));
                if (wire) {
                    wire.call('deleteContact', contactId);
                }
                this.exitRecall(inputEl);
            }
        },

        saveRecalled(inputEl) {
            if (!this.isRecalling) return;

            const exchange = inputEl.value.trim();
            if (!exchange) return;

            // Queue-sourced edit: parse client-side, mutate queue entry in place,
            // clear the sync lock back to 'pending' and kick sync.
            if (this.recalledUuid) {
                const parsed = this.parseExchange(exchange);
                if (!parsed) {
                    // parseError is already set by parseExchange; stay in recall mode.
                    return;
                }
                const advanced = this.parseAdvancedFields();
                if (!advanced) {
                    return;
                }

                const entry = this.queue.find(c => c.uuid === this.recalledUuid);
                if (entry) {
                    Object.assign(entry, this.emptyToNull(advanced));
                    entry.callsign = parsed.callsign;
                    entry.section_id = parsed.section_id;
                    entry.section_code = parsed.section_code;
                    entry.exchange_class = (parsed.transmitter_count + parsed.class_code).toUpperCase();
                    entry.status = 'pending';
                    entry.attempts = 0;
                    entry.last_error = null;
                    entry.lastAttemptTime = 0;
                    delete entry._prevStatus;
                    this.schedulePendingIndicator(entry);
                    this.saveQueue();
                }

                // exitRecall would try to release the lock, but we've already
                // transitioned the entry to 'pending'. Clear recalledUuid first
                // so _releaseRecallLock is a no-op.
                this.recalledUuid = null;
                this.exitRecall(inputEl);
                void this.syncNext();
                return;
            }

            // Server-sourced edit: delegate to Livewire.
            if (this.recalledContactId) {
                const advanced = this.parseAdvancedFields();
                if (!advanced) {
                    return;
                }
                const contactId = this.recalledContactId;
                const wire = globalThis.Livewire?.find(inputEl.closest(String.raw`[wire\:id]`)?.getAttribute('wire:id'));
                if (wire) {
                    wire.call('updateContact', contactId, exchange, this.emptyToNull(advanced));
                }
                this.exitRecall(inputEl);
            }
        },

        /**
         * The QSO log's details line for a queued contact. Mirrors
         * AdvancedContactFields::summary().
         */
        queuedSummary(contact) {
            const reports = contact.rst_sent || contact.rst_received
                ? `${contact.rst_sent || '–'}/${contact.rst_received || '–'}`
                : null;
            return [
                contact.frequency_hz ? String(contact.frequency_hz / 1_000_000) : null,
                reports,
                contact.grid_square,
                contact.contact_name,
            ].filter(Boolean).join(' · ');
        },

        /**
         * A short note of what's filled in, shown on the Advanced Fields
         * toggle while the panel is closed.
         */
        get advancedSummary() {
            const a = this.advanced;
            const reports = a.rst_sent || a.rst_received ? `${a.rst_sent || '–'}/${a.rst_received || '–'}` : null;
            return [a.frequency ? `${a.frequency} MHz` : null, reports, a.grid_square, a.contact_name, a.notes ? 'note' : null]
                .filter(Boolean).join(' · ');
        },

        /**
         * Advanced Fields form values from a queued or server contact.
         */
        advancedFromStored(stored) {
            return {
                frequency: stored.frequency_hz ? String(stored.frequency_hz / 1_000_000) : '',
                rst_sent: stored.rst_sent ?? '',
                rst_received: stored.rst_received ?? '',
                grid_square: stored.grid_square ?? '',
                contact_name: stored.contact_name ?? '',
                notes: stored.notes ?? '',
            };
        },

        loadQueue() {
            try {
                const stored = localStorage.getItem(this.storageKey);
                if (stored) {
                    // Synced entries are already on the server; the page load renders them.
                    this.queue = JSON.parse(stored).filter(c => c.status !== 'synced');
                    // Reset any contacts stuck in 'syncing' or 'editing' state
                    // (from page reload mid-sync or mid-edit)
                    this.queue.forEach(c => {
                        if (c.status === 'syncing' || c.status === 'editing') {
                            c.status = 'pending';
                            delete c._prevStatus;
                        }
                    });
                    this.saveQueue();
                }
            } catch (e) {
                // Corrupted or unparseable localStorage data - start with an empty queue
                console.warn('Failed to load contact queue from localStorage; starting fresh:', e);
                this.queue = [];
            }
        },

        saveQueue() {
            try {
                localStorage.setItem(this.storageKey, JSON.stringify(this.queue));
                this.storageFailing = false;
            } catch (e) {
                // localStorage full or unavailable - queue lives in memory only for this session;
                // storageWarningVisible tells the operator while contacts are waiting.
                this.storageFailing = true;
                console.warn('Failed to persist contact queue to localStorage:', e);
            }
        },
    };
}
