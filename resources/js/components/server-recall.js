// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Server Recall - Alpine.js recall/edit state for logs whose entries go
 * straight to the server: paper-log transcription and the net log.
 *
 * Exposes the same recall API as contactQueue, so these pages share the QSO
 * log and exchange composer components with live logging. Entries come from
 * the rendered desktop rows: a wire:key of "<rowPrefix><id>" and the line to
 * recall in data-recall-value. Struck-through (deleted) rows can't be recalled.
 * A save that renders a [data-recall-error] element stays in recall so the
 * line can be fixed.
 *
 * Usage in Blade:
 *   <div x-data="transcribeRecall"> or <div x-data="netLogRecall">
 */
function serverRecall({ rowPrefix, log, update, remove }) {
    return {
        recallIndex: -1,
        recalledContactId: null,
        recalledUuid: null,
        recalledExchange: '',

        get isRecalling() {
            return this.recallIndex >= 0;
        },

        /**
         * Recallable entries, newest first. Rows render oldest to newest, so
         * walk the table backwards.
         */
        get recallableEntries() {
            return [...document.querySelectorAll(String.raw`tr[wire\:key^="${rowPrefix}"]`)]
                .reverse()
                .filter(row => !row.classList.contains('line-through') && row.dataset.recallValue)
                .map(row => ({
                    id: Number.parseInt(row.getAttribute('wire:key').slice(rowPrefix.length), 10),
                    exchange: row.dataset.recallValue,
                }));
        },

        logContact(inputEl) {
            log(this.$wire, inputEl.value);
        },

        recallUp(inputEl) {
            const entries = this.recallableEntries;
            if (entries.length === 0) return;
            if (this.recallIndex < entries.length - 1) this.recallIndex++;
            this._recall(entries[this.recallIndex], inputEl);
        },

        recallDown(inputEl) {
            if (this.recallIndex <= 0) {
                this.exitRecall(inputEl);
                return;
            }
            this.recallIndex--;
            this._recall(this.recallableEntries[this.recallIndex], inputEl);
        },

        recallByContactId(id) {
            const inputEl = document.getElementById('exchange-input');
            if (!inputEl) return;
            const entries = this.recallableEntries;
            const index = entries.findIndex(entry => entry.id === id);
            if (index < 0) return;
            this.recallIndex = index;
            this._recall(entries[index], inputEl);
            this.$wire.set('exchangeInput', entries[index].exchange);
            inputEl.focus();
        },

        _recall(entry, inputEl) {
            if (!entry) return;
            inputEl.value = entry.exchange;
            this.recalledContactId = entry.id;
            this.recalledExchange = entry.exchange;
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
            const id = this.recalledContactId;
            this.exitRecall(inputEl);
            remove(this.$wire, id);
        },

        async saveRecalled(inputEl) {
            if (!this.isRecalling || !this.recalledContactId) return;
            const exchange = inputEl.value.trim();
            if (!exchange) return;
            await update(this.$wire, this.recalledContactId, exchange);
            if (!this.$wire.$el.querySelector('[data-recall-error]')) {
                this.exitRecall(inputEl);
            }
        },
    };
}

export const transcribeRecall = () => serverRecall({
    rowPrefix: 'contact-',
    log: wire => wire.logContact(),
    update: (wire, id, exchange) => wire.updateContact(id, exchange),
    remove: (wire, id) => wire.deleteContact(id),
});

export const netLogRecall = () => serverRecall({
    rowPrefix: 'net-entry-',
    log: (wire, line) => line.trim() !== '' && wire.logEntry(line),
    update: (wire, id, line) => wire.updateEntry(id, line),
    remove: (wire, id) => wire.deleteEntry(id),
});
