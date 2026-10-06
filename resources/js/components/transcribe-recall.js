/**
 * Transcribe Recall - Alpine.js recall/edit state for paper-log transcription.
 *
 * Exposes the same recall API as contactQueue so the transcription page can
 * share the QSO log and exchange composer components with live logging.
 * Transcribed contacts go straight to the server, so there is no local queue.
 *
 * Usage in Blade:
 *   <div x-data="transcribeRecall">
 */
export default function transcribeRecall() {
    return {
        recallIndex: -1,
        recalledContactId: null,
        recalledUuid: null,
        recalledExchange: '',

        get isRecalling() {
            return this.recallIndex >= 0;
        },

        /**
         * Server-confirmed contacts from the DOM table rows, newest first
         * (recall walks back in time). The table renders oldest → newest,
         * so walk it backwards.
         */
        get recallableContacts() {
            const rows = [...document.querySelectorAll(String.raw`tr[wire\:key^="contact-"]`)].reverse();
            const contacts = [];
            rows.forEach(row => {
                if (row.classList.contains('line-through')) return;
                const contactId = parseInt(row.getAttribute('wire:key').replace('contact-', ''));
                const recallValue = row.dataset.recallValue;
                if (contactId && recallValue) {
                    contacts.push({ id: contactId, exchange: recallValue });
                }
            });
            return contacts;
        },

        logContact() {
            this.$wire.logContact();
        },

        recallUp(inputEl) {
            const contacts = this.recallableContacts;
            if (contacts.length === 0) return;
            if (this.recallIndex < contacts.length - 1) this.recallIndex++;
            this._recall(contacts[this.recallIndex], inputEl);
        },

        recallDown(inputEl) {
            if (this.recallIndex <= 0) {
                this.exitRecall(inputEl);
                return;
            }
            this.recallIndex--;
            this._recall(this.recallableContacts[this.recallIndex], inputEl);
        },

        recallByContactId(id) {
            const inputEl = document.getElementById('exchange-input');
            if (!inputEl) return;
            const contacts = this.recallableContacts;
            const index = contacts.findIndex(c => c.id === id);
            if (index < 0) return;
            this.recallIndex = index;
            this._recall(contacts[index], inputEl);
            this.$wire.set('exchangeInput', contacts[index].exchange);
            inputEl.focus();
        },

        _recall(contact, inputEl) {
            if (!contact) return;
            inputEl.value = contact.exchange;
            this.recalledContactId = contact.id;
            this.recalledExchange = contact.exchange;
        },

        exitRecall(inputEl) {
            this.recallIndex = -1;
            this.recalledContactId = null;
            this.recalledExchange = '';
            if (inputEl) {
                inputEl.value = '';
                this.$wire.set('exchangeInput', '');
                inputEl.focus();
            }
        },

        deleteRecalled(inputEl) {
            if (!this.isRecalling || !this.recalledContactId) return;
            this.$wire.call('deleteContact', this.recalledContactId);
            this.exitRecall(inputEl);
        },

        saveRecalled(inputEl) {
            if (!this.isRecalling || !this.recalledContactId) return;
            const exchange = inputEl.value.trim();
            if (!exchange) return;
            this.$wire.call('updateContact', this.recalledContactId, exchange);
            this.exitRecall(inputEl);
        },
    };
}
