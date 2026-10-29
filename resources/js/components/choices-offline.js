// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Choices Offline - Alpine.js component behind <x-choices-offline>.
 *
 * A searchable single or multi picker whose option rows are rendered in the
 * browser from one template, instead of the server sending a fully styled
 * row for every option. A 420-entry time zone list drops from ~900 KB of
 * markup to ~20 KB of JSON.
 *
 * Options are read from the root's data-options attribute ([{v, l, s?}]),
 * not passed into x-data, because a Livewire morph updates attributes but
 * doesn't re-run x-data. Reading them on access keeps the list current when
 * the server re-renders with different options.
 *
 * Only the first `limit` matches are rendered; typing narrows the rest.
 */
export default function choicesOffline(config) {
    return {
        selection: config.selection,
        single: Boolean(config.single),
        searchable: Boolean(config.searchable),
        required: Boolean(config.required),
        locked: Boolean(config.readonly || config.disabled),
        limit: config.limit ?? 100,
        open: false,
        refocusing: false,
        search: '',
        rawOptions: null,
        parsedOptions: [],

        get options() {
            const raw = this.$root.dataset.options ?? '[]';

            if (raw !== this.rawOptions) {
                this.rawOptions = raw;
                this.parsedOptions = JSON.parse(raw);
            }

            return this.parsedOptions;
        },

        get selectedValues() {
            if (this.single) {
                return this.isBlank(this.selection) ? [] : [this.selection];
            }

            return Array.isArray(this.selection) ? this.selection : [];
        },

        get selectedOptions() {
            return this.selectedValues
                .map((value) => this.options.find((option) => option.v == value))
                .filter(Boolean);
        },

        get isSelectionEmpty() {
            return this.selectedValues.length === 0;
        },

        get matches() {
            const needle = this.search.trim().toLowerCase();

            if (needle === '') {
                return this.options;
            }

            return this.options.filter((option) =>
                option.l.toLowerCase().includes(needle) || (option.s ?? '').toLowerCase().includes(needle));
        },

        get visibleOptions() {
            return this.matches.slice(0, this.limit);
        },

        get hiddenCount() {
            return Math.max(0, this.matches.length - this.limit);
        },

        isBlank(value) {
            return value === null || value === undefined || value === '';
        },

        isActive(value) {
            return this.selectedValues.some((selected) => selected == value);
        },

        show() {
            if (this.locked || this.refocusing) {
                return;
            }

            this.open = true;
            this.$refs.searchInput.focus();
        },

        close() {
            this.open = false;
            this.search = '';
        },

        closeIfFocusLeft(event) {
            if (!this.$root.contains(event.relatedTarget)) {
                this.close();
            }
        },

        toggle(value) {
            if (this.locked) {
                return;
            }

            if (this.single) {
                this.selection = value;
                this.close();

                // Hand focus back to the field without reopening the list.
                this.refocusing = true;
                this.$refs.searchInput.focus();
                this.refocusing = false;
            } else if (this.isActive(value)) {
                this.selection = this.selectedValues.filter((selected) => selected != value);
            } else {
                this.selection = [...this.selectedValues, value];
            }
        },

        remove(value) {
            if (!this.locked) {
                this.selection = this.selectedValues.filter((selected) => selected != value);
            }
        },

        focusOption(offset) {
            const rows = Array.from(this.$refs.list.querySelectorAll('[role="option"]'));

            if (rows.length === 0) {
                return;
            }

            const index = rows.indexOf(document.activeElement);
            let next = index + offset;

            // From the search field, down enters at the top and up at the bottom.
            if (index === -1) {
                next = offset > 0 ? 0 : rows.length - 1;
            }

            if (next < 0) {
                this.$refs.searchInput.focus();
            } else if (rows[next]) {
                rows[next].focus();
            }
        },

        resizeInput() {
            this.$refs.searchInput.style.width = `${(this.search.length + 1) * 0.55}rem`;
        },
    };
}
