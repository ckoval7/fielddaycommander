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

        init() {
            this.loadQueue();

            globalThis.addEventListener('online', () => {
                this.isOnline = true;
                this.syncNext();
            });
            globalThis.addEventListener('offline', () => {
                this.isOnline = false;
            });

            this.syncIntervalId = setInterval(() => this.syncNext(), 3000);
        },

        destroy() {
            if (this.syncIntervalId) {
                clearInterval(this.syncIntervalId);
            }
        },

        get pendingContacts() {
            return this.queue.filter(c => c.status === 'pending' || c.status === 'syncing');
        },

        get failedContacts() {
            return this.queue.filter(c => c.status === 'failed');
        },

        get pendingCount() {
            return this.pendingContacts.length;
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
            if (this.pendingCount > 0) {
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
                qso_time: new Date().toISOString(),
                status: 'pending',
                attempts: 0,
                last_error: null,
            };

            this.queue.unshift(entry);
            this.saveQueue();
            this.syncNext();
        },

        /**
         * Parse exchange client-side and queue to localStorage immediately.
         * No server round-trip — sync happens in the background.
         */
        logContact(inputEl) {
            const rawInput = (inputEl?.value || '').trim();
            if (!rawInput) {
                this.parseError = 'Exchange is empty';
                return;
            }

            const parsed = this.parseExchange(rawInput);
            if (!parsed) return; // parseError already set

            this.enqueue({
                band_id: sessionContext.band_id,
                mode_id: sessionContext.mode_id,
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
         * Client-side exchange parser.
         * Format: CALLSIGN CLASS SECTION (e.g. "W1AW 3A CT")
         */
        parseExchange(input) {
            const tokens = input.toUpperCase().trim().split(/\s+/);

            if (tokens.length < 3) {
                this.parseError = 'Exchange must contain callsign, class, and section (e.g. W1AW 3A CT)';
                return null;
            }
            if (tokens.length > 3) {
                this.parseError = 'Too many parts in exchange';
                return null;
            }

            const callsign = tokens[0];
            if (callsign.length < 3 || callsign.length > 10 ||
                !/\d/.test(callsign) || !/[A-Z]/.test(callsign) ||
                !/^[A-Z0-9/]+$/.test(callsign)) {
                this.parseError = `Invalid callsign: ${callsign}`;
                return null;
            }

            if (!/^\d{1,2}[A-F]$/i.test(tokens[1])) {
                this.parseError = `Invalid class: ${tokens[1]} (expected format like 3A, 1D)`;
                return null;
            }

            const sectionCode = tokens[2];
            const sectionId = sessionContext.sections?.[sectionCode];
            if (!sectionId) {
                this.parseError = `Unknown section: ${sectionCode}`;
                return null;
            }

            const classMatch = tokens[1].match(/^(\d{1,2})([A-F])$/i);
            const transmitter_count = classMatch[1];
            const class_code = classMatch[2].toUpperCase();

            this.parseError = '';
            return { callsign, section_id: sectionId, section_code: sectionCode, transmitter_count, class_code };
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

                if (response.ok) {
                    await response.json();
                    this.queue = this.queue.filter(c => c.uuid !== candidate.uuid);
                    this.saveQueue();

                    // Notify the Livewire component to refresh the recent
                    // contacts list. Uses Livewire's event dispatch which is
                    // reliable after wire:navigate SPA transitions.
                    globalThis.Livewire?.dispatch('contact-synced');
                } else if (response.status === 422) {
                    const errorData = await response.json();
                    candidate.status = 'failed';
                    candidate.attempts++;
                    candidate.lastAttemptTime = Date.now();
                    candidate.last_error = errorData.message || 'Validation failed';
                    this.saveQueue();
                } else if (response.status === 401 || response.status === 419) {
                    // Session or CSRF expired - stop retrying, user must refresh
                    candidate.status = 'failed';
                    candidate.attempts = 999;
                    candidate.last_error = 'Session expired - please refresh the page';
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
                contact.status = 'pending';
                contact.attempts = 0;
                contact.last_error = null;
                this.saveQueue();
                this.syncNext();
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
            // Get server-confirmed contacts from the DOM table rows
            const rows = document.querySelectorAll(String.raw`tr[wire\:key^="contact-"]`);
            const contacts = [];
            rows.forEach(row => {
                // Skip deleted rows (they have line-through class)
                if (row.classList.contains('line-through')) return;
                const callsignCell = row.querySelector('td:nth-child(3)');
                const exchangeCell = row.querySelector('td:nth-child(4)');
                const wireKey = row.getAttribute('wire:key');
                const contactId = wireKey ? Number.parseInt(wireKey.replace('contact-', '')) : null;
                if (contactId && callsignCell && exchangeCell) {
                    contacts.push({
                        id: contactId,
                        callsign: callsignCell.textContent.trim().split('\n')[0].trim(),
                        exchange: exchangeCell.textContent.trim(),
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
                inputEl.value = contact.exchange;
                this.recalledContactId = contact.id;
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
                inputEl.value = contact.exchange;
                this.recalledContactId = contact.id;
            }
        },

        exitRecall(inputEl) {
            this.recallIndex = -1;
            this.recalledContactId = null;
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
            if (!this.isRecalling || !this.recalledContactId) return;

            const contactId = this.recalledContactId;
            const wire = globalThis.Livewire?.find(inputEl.closest(String.raw`[wire\:id]`)?.getAttribute('wire:id'));
            if (wire) {
                wire.call('deleteContact', contactId);
            }
            this.exitRecall(inputEl);
        },

        saveRecalled(inputEl) {
            if (!this.isRecalling || !this.recalledContactId) return;

            const contactId = this.recalledContactId;
            const exchange = inputEl.value.trim();
            if (!exchange) return;

            const wire = globalThis.Livewire?.find(inputEl.closest(String.raw`[wire\:id]`)?.getAttribute('wire:id'));
            if (wire) {
                wire.call('updateContact', contactId, exchange);
            }
            this.exitRecall(inputEl);
        },

        loadQueue() {
            try {
                const stored = localStorage.getItem(this.storageKey);
                if (stored) {
                    this.queue = JSON.parse(stored);
                    // Reset any contacts stuck in 'syncing' state (from page reload mid-sync)
                    this.queue.forEach(c => {
                        if (c.status === 'syncing') {
                            c.status = 'pending';
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
