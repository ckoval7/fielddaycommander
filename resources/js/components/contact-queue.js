/**
 * Contact Queue - Alpine.js store-and-forward component for contact logging.
 *
 * Manages a localStorage-backed queue of contacts. Contacts are queued locally
 * and synced to the server via the /api/logging/contacts endpoint. Handles
 * offline detection, retry with exponential backoff, and sync status display.
 *
 * Usage in Blade:
 *   <div x-data="contactQueue(sessionId, csrfToken)">
 */
export default function contactQueue(sessionId, csrfToken) {
    return {
        queue: [],
        isOnline: navigator.onLine,
        syncIntervalId: null,
        storageKey: `fd-commander-queue-${sessionId}`,

        init() {
            this.loadQueue();

            window.addEventListener('online', () => {
                this.isOnline = true;
                this.syncNext();
            });
            window.addEventListener('offline', () => {
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
                uuid: crypto.randomUUID(),
                operating_session_id: sessionId,
                band_id: contactData.band_id,
                mode_id: contactData.mode_id,
                callsign: contactData.callsign,
                section_id: contactData.section_id,
                section_code: contactData.section_code || '',
                received_exchange: contactData.received_exchange,
                power_watts: contactData.power_watts,
                qso_time: new Date().toISOString(),
                status: 'pending',
                attempts: 0,
                last_error: null,
            };

            this.queue.unshift(entry);
            this.saveQueue();
            this.syncNext();
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
                const response = await fetch('/api/logging/contacts', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        uuid: candidate.uuid,
                        operating_session_id: candidate.operating_session_id,
                        band_id: candidate.band_id,
                        mode_id: candidate.mode_id,
                        callsign: candidate.callsign,
                        section_id: candidate.section_id,
                        received_exchange: candidate.received_exchange,
                        power_watts: candidate.power_watts,
                        qso_time: candidate.qso_time,
                    }),
                });

                if (response.ok) {
                    const data = await response.json();
                    this.queue = this.queue.filter(c => c.uuid !== candidate.uuid);
                    this.saveQueue();

                    // Tell Livewire to refresh the recent contacts list
                    this.$dispatch('contact-synced', {
                        uuid: candidate.uuid,
                        contact_id: data.contact_id,
                        points: data.points,
                        is_duplicate: data.is_duplicate,
                    });
                } else if (response.status === 422) {
                    const errorData = await response.json();
                    candidate.status = 'failed';
                    candidate.attempts++;
                    candidate.lastAttemptTime = Date.now();
                    candidate.last_error = errorData.message || 'Validation failed';
                    this.saveQueue();
                } else if (response.status === 401) {
                    // Session expired - mark failed with clear message
                    candidate.status = 'failed';
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
                // Network error - retry
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
            this.$dispatch('contact-discarded');
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
                this.queue = [];
            }
        },

        saveQueue() {
            try {
                localStorage.setItem(this.storageKey, JSON.stringify(this.queue));
            } catch (e) {
                // localStorage full or unavailable - queue lives in memory only
            }
        },
    };
}
