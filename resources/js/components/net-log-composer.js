// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Net Log Composer - Alpine.js scope for the net log's exchange composer.
 *
 * Provides the recall API the shared exchange composer and QSO log expect
 * (see resources/views/components/logging/exchange-composer.blade.php), so
 * the net log works like the contact log: type a line and press Enter, use
 * the arrow keys or tap a row to recall an entry, then Save, Delete or
 * Cancel. Entries come from the rendered rows, which carry their id and
 * their line as data attributes.
 *
 * Usage in Blade: <div x-data="netLogComposer()"> ... </div>
 */
export default function netLogComposer() {
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
         * walk the desktop table backwards; deleted rows can't be recalled.
         */
        get recallableEntries() {
            return [...document.querySelectorAll('tr[data-net-entry-id]')]
                .reverse()
                .filter(row => row.dataset.deleted !== 'true')
                .map(row => ({
                    id: Number.parseInt(row.dataset.netEntryId, 10),
                    line: row.dataset.exchange,
                }));
        },

        logContact(inputEl) {
            if (inputEl.value.trim() === '') return;
            this.$wire.logEntry(inputEl.value);
        },

        saveRecalled(inputEl) {
            if (!this.isRecalling) return;
            this.$wire.updateEntry(this.recalledContactId, inputEl.value).then(() => {
                if (!this.$wire.$el.querySelector('[data-net-log-error]')) {
                    this.exitRecall(inputEl);
                }
            });
        },

        deleteRecalled(inputEl) {
            if (!this.isRecalling) return;
            const id = this.recalledContactId;
            this.exitRecall(inputEl);
            this.$wire.deleteEntry(id);
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

        recallUp(inputEl) {
            const entries = this.recallableEntries;
            if (entries.length === 0) return;
            if (this.recallIndex < entries.length - 1) {
                this.recallIndex++;
            }
            this._apply(entries[this.recallIndex], inputEl);
        },

        recallDown(inputEl) {
            if (this.recallIndex <= 0) {
                this.exitRecall(inputEl);
                return;
            }
            this.recallIndex--;
            this._apply(this.recallableEntries[this.recallIndex], inputEl);
        },

        recallByContactId(id) {
            const input = document.getElementById('exchange-input');
            if (!input) return;
            const entries = this.recallableEntries;
            const index = entries.findIndex(entry => entry.id === id);
            if (index < 0) return;
            this.recallIndex = index;
            this._apply(entries[index], input);
            input.focus();
        },

        _apply(entry, inputEl) {
            if (!entry) return;
            inputEl.value = entry.line;
            this.recalledExchange = entry.line;
            this.recalledContactId = entry.id;
        },
    };
}
