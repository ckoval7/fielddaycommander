// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * App-wide confirmation modal, in place of the browser's confirm() and
 * prompt(). Those look and behave differently in every browser: some block
 * the page, some offer to suppress further dialogs (after which every
 * confirm() silently returns false), and some installed PWAs never show them.
 *
 * - Every `wire:confirm` opens this modal. Livewire's own directive sets
 *   `el.__livewire_confirm`; a directive.init hook registered after it swaps
 *   in a version that asks here and runs the action once confirmed.
 *   `wire:confirm.prompt="Question|EXPECTED"` asks the person to type EXPECTED.
 * - Alpine code calls `$confirm('Message?')` or `$confirm({ message,
 *   confirmLabel, danger })`, which resolves to true or false.
 *
 * The confirm button takes the clicked element's text ("Delete", "Exit
 * Station Mode") when it is short, and turns red when the element is styled
 * as an error action. `data-confirm-label` and `data-confirm-danger` on the
 * element override both.
 *
 * Rendered once per layout by <x-confirm-dialog />.
 */
const DANGER_CLASS = /\b(btn-error|text-error)\b/;
const MAX_LABEL_LENGTH = 24;

export function registerConfirmDialog(Alpine, Livewire) {
    Alpine.store('confirmDialog', {
        open: false,
        message: '',
        confirmLabel: 'Confirm',
        danger: false,
        expected: null,
        typed: '',
        resolve: null,

        ask({ message = 'Are you sure?', confirmLabel = 'Confirm', danger = false, expected = null } = {}) {
            // A second request replaces an unanswered one, which counts as cancelled.
            this.settle(false);

            Object.assign(this, { message, confirmLabel, danger, expected, typed: '', open: true });

            return new Promise((resolve) => {
                this.resolve = resolve;
            });
        },

        get canConfirm() {
            return this.expected === null || this.typed === this.expected;
        },

        settle(confirmed) {
            const resolve = this.resolve;
            const accepted = confirmed && this.canConfirm;

            this.resolve = null;
            this.open = false;
            resolve?.(accepted);
        },
    });

    Alpine.magic('confirm', () => (options) =>
        Alpine.store('confirmDialog').ask(typeof options === 'string' ? { message: options } : options),
    );

    Livewire.hook('directive.init', ({ el, directive }) => {
        if (directive.value !== 'confirm') {
            return;
        }

        const options = confirmOptions(el, directive);

        el.__livewire_confirm = (action, instead) => {
            // A checkbox or toggle has already flipped by the time the click
            // handler runs. Put it back until the person confirms.
            const flipped = el instanceof HTMLInputElement && el.type === 'checkbox';

            if (flipped) {
                el.checked = !el.checked;
            }

            Alpine.store('confirmDialog').ask(options).then((confirmed) => {
                if (!confirmed) {
                    instead();
                    return;
                }

                if (flipped) {
                    el.checked = !el.checked;
                }

                action();
            });
        };
    });

    document.addEventListener('livewire:navigate', () => Alpine.store('confirmDialog').settle(false));
}

/**
 * Behaviour for the <dialog> in <x-confirm-dialog />: open and close it with
 * the store, and focus the safe choice first.
 */
export function confirmDialogPanel() {
    return {
        init() {
            this.$watch('$store.confirmDialog.open', (open) => (open ? this.show() : this.hide()));
        },

        show() {
            if (!this.$el.open) {
                this.$el.showModal();
            }

            this.$nextTick(() => {
                const store = this.$store.confirmDialog;
                const target = store.expected === null
                    ? (store.danger ? this.$refs.cancel : this.$refs.confirm)
                    : this.$refs.typed;

                target?.focus();
            });
        },

        hide() {
            if (this.$el.open) {
                this.$el.close();
            }
        },
    };
}

function confirmOptions(el, directive) {
    let message = directive.expression.replaceAll(String.raw`\n`, '\n') || 'Are you sure?';
    let expected = null;

    if (directive.modifiers.includes('prompt')) {
        [message, expected = ''] = message.split('|');
    }

    return {
        message,
        expected,
        confirmLabel: el.dataset.confirmLabel || elementLabel(el) || 'Confirm',
        danger: el.dataset.confirmDanger === undefined
            ? DANGER_CLASS.test(el.getAttribute('class') ?? '')
            : el.dataset.confirmDanger !== 'false',
    };
}

function elementLabel(el) {
    const text = (el.innerText || '').replaceAll(/\s+/g, ' ').trim();

    return text.length > 0 && text.length <= MAX_LABEL_LENGTH ? text : null;
}
