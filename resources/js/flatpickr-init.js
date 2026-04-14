import flatpickr from 'flatpickr';

export default function flatpickrComponent({ mode = 'datetime', min = null, max = null, model = null, nowUtc = true }) {
    return {
        instance: null,
        nowUtc,

        init() {
            const config = {
                allowInput: true,
                appendTo: document.body,
                onChange: (selectedDates, dateStr) => {
                    this.$el.querySelector('input').value = dateStr;
                    this.$el.querySelector('input').dispatchEvent(new Event('input', { bubbles: true }));
                },
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

            this.instance = flatpickr(this.$el.querySelector('input'), config);

            if (model && this.$wire) {
                const initialValue = this.$wire.get(model);
                if (initialValue) {
                    this.instance.setDate(initialValue, true);
                }

                this.$wire.$watch(model, (value) => {
                    this.instance.setDate(value || '', true);
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
