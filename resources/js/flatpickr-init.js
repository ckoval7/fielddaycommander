import flatpickr from 'flatpickr';

export default function flatpickrComponent({ mode = 'datetime', min = null, max = null, model = null, nowUtc = true }) {
    return {
        instance: null,
        nowUtc,

        init() {
            const inputEl = this.$el.querySelector('input');

            const config = {
                allowInput: true,
                // Render inline so the calendar stays inside any focus-trapped
                // ancestor (e.g. Mary's modal uses x-trap, which would yank
                // focus out of the picker's hour/minute fields if it were
                // appended to document.body).
                static: true,
                onChange: (selectedDates, dateStr) => {
                    inputEl.value = dateStr;
                    inputEl.dispatchEvent(new Event('input', { bubbles: true }));
                },
                // Each sibling form row is its own stacking context (daisyUI
                // `.input` sets position:relative), so the calendar can't
                // escape via z-index alone — lift the whole wrapper while open.
                onOpen: () => this.$el.classList.add('flatpickr-wrapper-open'),
                onClose: () => this.$el.classList.remove('flatpickr-wrapper-open'),
            };

            if (mode === 'datetime') {
                config.enableTime = true;
                config.noCalendar = false;
                config.dateFormat = 'Y-m-d H:i';
                config.time_24hr = true;
            } else if (mode === 'date') {
                config.enableTime = false;
                config.noCalendar = false;
                config.dateFormat = 'Y-m-d';
            } else if (mode === 'time') {
                config.enableTime = true;
                config.noCalendar = true;
                config.dateFormat = 'H:i';
                config.time_24hr = true;
            }

            if (min) config.minDate = min;
            if (max) config.maxDate = max;

            this.instance = flatpickr(inputEl, config);

            if (model && this.$wire) {
                const initialValue = this.$wire.get(model);
                if (initialValue) {
                    this.instance.setDate(initialValue, true);
                }

                this.$wire.$watch(model, (value) => {
                    const active = document.activeElement;
                    const calendar = this.instance?.calendarContainer;

                    // While the user is typing in the main input or the
                    // picker's own fields, setDate would re-render and steal
                    // focus mid-keystroke.
                    if (active === inputEl || calendar?.contains(active)) {
                        return;
                    }

                    if (!value) {
                        this.instance.clear();
                        return;
                    }

                    // Avoids the onChange -> wire:model -> $watch -> setDate
                    // -> onChange feedback loop.
                    if (inputEl.value === value) {
                        return;
                    }

                    try {
                        this.instance.setDate(value, true);
                    } catch (e) {
                        // Ignore partial values flatpickr can't parse yet.
                    }
                });
            }
        },

        setNow() {
            const now = new Date();
            const pad = (n) => String(n).padStart(2, '0');

            let dateStr;

            if (this.nowUtc) {
                const y = now.getUTCFullYear();
                const m = pad(now.getUTCMonth() + 1);
                const d = pad(now.getUTCDate());
                const h = pad(now.getUTCHours());
                const min = pad(now.getUTCMinutes());

                if (mode === 'date') {
                    dateStr = `${y}-${m}-${d}`;
                } else if (mode === 'time') {
                    dateStr = `${h}:${min}`;
                } else {
                    dateStr = `${y}-${m}-${d} ${h}:${min}`;
                }
            } else {
                const y = now.getFullYear();
                const m = pad(now.getMonth() + 1);
                const d = pad(now.getDate());
                const h = pad(now.getHours());
                const min = pad(now.getMinutes());

                if (mode === 'date') {
                    dateStr = `${y}-${m}-${d}`;
                } else if (mode === 'time') {
                    dateStr = `${h}:${min}`;
                } else {
                    dateStr = `${y}-${m}-${d} ${h}:${min}`;
                }
            }

            this.instance.setDate(dateStr, true);
        },

        destroy() {
            if (this.instance) {
                this.instance.destroy();
            }
        },
    };
}
