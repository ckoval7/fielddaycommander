import flatpickr from 'flatpickr';

export default function flatpickrComponent({ mode = 'datetime', min = null, max = null, model = null }) {
    return {
        instance: null,

        init() {
            const config = {
                allowInput: true,
                static: true,
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

        destroy() {
            if (this.instance) {
                this.instance.destroy();
            }
        },
    };
}
