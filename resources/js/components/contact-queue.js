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
export default function contactQueue(sessionId, csrfToken, sessionContext) {
    return {
        queue: [],
        isOnline: navigator.onLine,
        syncIntervalId: null,
        storageKey: `fd-commander-queue-${sessionId}`,
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

        init() {
            this.loadQueue();

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

            if (sessionContext.is_gota && !this.hasGotaOperator()) {
                this.parseError = 'Enter the GOTA operator in the GOTA Operator panel before logging.';
                return;
            }

            const parsed = this.parseExchange(rawInput);
            if (!parsed) return; // parseError already set

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
            });

            // Clear input and refocus
            inputEl.value = '';
            this.parseError = '';
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
                    candidate.last_error = 'Session expired. Refresh the page, then retry.';
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

                const entry = this.queue.find(c => c.uuid === this.recalledUuid);
                if (entry) {
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
                const contactId = this.recalledContactId;
                const wire = globalThis.Livewire?.find(inputEl.closest(String.raw`[wire\:id]`)?.getAttribute('wire:id'));
                if (wire) {
                    wire.call('updateContact', contactId, exchange);
                }
                this.exitRecall(inputEl);
            }
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
            } catch (e) {
                // localStorage full or unavailable - queue lives in memory only for this session
                console.warn('Failed to persist contact queue to localStorage:', e);
            }
        },
    };
}
