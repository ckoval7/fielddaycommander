// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * App-wide floating validation summary.
 *
 * The server-side ReportValidationFailures Livewire hook attaches a
 * `validationErrors` effect whenever an action (save, submit, ...) fails
 * validation. This listener picks that up for every component, shows the
 * floating summary rendered by <x-form-error-summary /> in the layouts, and
 * scrolls the first invalid field into view — so a failed submit never looks
 * like "nothing happened" when the errors are off-screen.
 */
const EFFECT = 'validationErrors';

export function registerFormErrorSummary(Alpine, Livewire) {
    Alpine.store('formErrors', {
        open: false,
        revision: 0,
        component: null,
        errors: {},

        get messages() {
            return Object.entries(this.errors).flatMap(([field, messages]) =>
                messages.map((message, index) => ({ id: `${field}:${index}`, field, message })),
            );
        },

        show(component, errors) {
            this.component = component;
            this.errors = errors;
            this.open = true;
            this.revision++;
            this.reveal(this.firstErrorField());
        },

        sync(component, errors) {
            if (this.component?.id !== component.id) {
                return;
            }

            if (Object.keys(errors).length === 0) {
                this.dismiss();
            } else {
                this.errors = errors;
            }
        },

        dismiss() {
            this.open = false;
            this.component = null;
        },

        focusField(key) {
            this.reveal(this.fields().find((el) => modelName(el) === key));
        },

        firstErrorField() {
            const keys = new Set(Object.keys(this.errors));

            return this.fields().find((el) => keys.has(modelName(el)));
        },

        fields() {
            const root = this.component?.el ?? document;

            return Array.from(root.querySelectorAll('input, select, textarea'));
        },

        reveal(field) {
            if (!field) {
                return;
            }

            field.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Focusing a flatpickr input pops its calendar open; scrolling is enough there.
            if (!field._flatpickr && !field.disabled) {
                field.focus({ preventScroll: true });
            }
        },
    });

    Livewire.interceptMessage(({ message, onSuccess }) => {
        onSuccess(({ payload, onRender }) => {
            const store = Alpine.store('formErrors');
            const errors = payload.effects?.[EFFECT];

            if (errors) {
                onRender(() => store.show(message.component, errors));
            } else {
                store.sync(message.component, payload.snapshot?.memo?.errors ?? {});
            }
        });
    });

    document.addEventListener('livewire:navigate', () => Alpine.store('formErrors').dismiss());
}

/**
 * Panel behaviour for <x-form-error-summary />. The panel is a manual popover
 * so it renders in the top layer, above open <dialog> modals as well.
 *
 * While it is open it publishes the space it takes at the bottom of the
 * screen as --form-error-summary-reserve and marks the root element, so an
 * open modal can end above it (see app.css) instead of having its action
 * buttons covered on a phone.
 */
export function formErrorSummaryPanel() {
    return {
        init() {
            this.$watch('$store.formErrors.revision', () => this.raise());
            this.$watch('$store.formErrors.open', (open) => (open ? this.raise() : this.lower()));

            if (typeof ResizeObserver === 'function') {
                new ResizeObserver(() => this.reserveSpace()).observe(this.$el);
            }
        },

        raise() {
            if (typeof this.$el.showPopover !== 'function') {
                return;
            }

            // Re-showing moves the panel to the top of the top layer, above any dialog opened since.
            this.lower();
            this.$el.showPopover();
            this.reserveSpace();
        },

        lower() {
            if (this.isOpen()) {
                this.$el.hidePopover();
            }

            this.releaseSpace();
        },

        isOpen() {
            return this.$el.matches?.(':popover-open') ?? false;
        },

        reserveSpace() {
            if (!this.isOpen()) {
                this.releaseSpace();

                return;
            }

            const root = document.documentElement;
            const reserve = Math.ceil(window.innerHeight - this.$el.getBoundingClientRect().top);

            root.style.setProperty('--form-error-summary-reserve', `${Math.max(0, reserve)}px`);
            root.dataset.formErrorSummary = 'open';
        },

        releaseSpace() {
            const root = document.documentElement;

            root.style.removeProperty('--form-error-summary-reserve');
            delete root.dataset.formErrorSummary;
        },
    };
}

function modelName(el) {
    const attribute = el.getAttributeNames().find((name) => name.startsWith('wire:model'));

    return attribute ? el.getAttribute(attribute) : null;
}
