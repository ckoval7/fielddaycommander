// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

import './bootstrap';
import dashboardSortable from './components/dashboard-sortable';
import contactQueue, { stampQueuedSessions } from './components/contact-queue';
import transcribeRecall from './components/transcribe-recall';
import updatedAgo from './components/updated-ago';
import choicesOffline from './components/choices-offline';
import { copyToClipboard } from './clipboard';
import { formErrorSummaryPanel, registerFormErrorSummary } from './components/form-error-summary';
import { confirmDialogPanel, registerConfirmDialog } from './components/confirm-dialog';
import { registerAnnouncer } from './components/announcer';
import { spokenAbbreviations, spokenDuration } from './spoken';
import flatpickrComponent from './flatpickr-init';
import { Chart, registerables } from 'chart.js/auto';

// Make Chart.js globally available for dynamic imports
Chart.register(...registerables);
globalThis.Chart = Chart;

// Station-mode sign-out forms report which sessions still have contacts queued here.
globalThis.stampQueuedSessions = stampQueuedSessions;

// Set initial theme on page load (this is redundant with inline script but kept for fallback)
let theme = localStorage.getItem('theme');
if (!theme) {
    theme = 'light';
    localStorage.setItem('theme', theme);
}
document.documentElement.dataset.theme = theme;

// Register Alpine.js components before Alpine starts
document.addEventListener('alpine:init', () => {
    Alpine.data('dashboardSortable', dashboardSortable);
    Alpine.data('contactQueue', contactQueue);
    Alpine.data('transcribeRecall', transcribeRecall);
    Alpine.data('updatedAgo', updatedAgo);
    Alpine.data('choicesOffline', choicesOffline);
    Alpine.data('formErrorSummaryPanel', formErrorSummaryPanel);
    Alpine.data('confirmDialogPanel', confirmDialogPanel);
    Alpine.data('flatpickr', flatpickrComponent);
    Alpine.magic('copy', () => copyToClipboard);
});

registerAnnouncer();

// For inline Alpine expressions that can't import it (the header countdown).
globalThis.fdcSpokenDuration = spokenDuration;
globalThis.fdcSpokenAbbreviations = spokenAbbreviations;

// Suppress only the W3C-spec'd benign "ResizeObserver loop completed with
// undelivered notifications" message. The browser raises this intentionally
// when it breaks a potential observer feedback loop; the spec authors
// document it as harmless. It is emitted from inside Floating UI (used by
// Livewire/Alpine and Mary UI for select, anchor, and tooltip positioning),
// which we do not control. Match the message string exactly so real errors
// — including any other ResizeObserver issue — still surface.
const RESIZE_OBSERVER_LOOP_MESSAGE = 'ResizeObserver loop completed with undelivered notifications.';
globalThis.addEventListener('error', (event) => {
    if (event.message === RESIZE_OBSERVER_LOOP_MESSAGE) {
        event.stopImmediatePropagation();
    }
});

// Graceful handling of failed Livewire XHR requests. We suppress Livewire's
// default "Page Expired" / Whoops modals and surface a toast (or a redirect,
// for 419) so the app degrades more gracefully when the session expires or
// the server is unhealthy. The unhandled-rejection noise from Livewire's
// rejected action promises is silenced earlier in the page lifecycle by the
// inline <x-silence-livewire-rejections /> partial — registering there means
// it runs before laravel/boost's BrowserLogger listener.
document.addEventListener('livewire:init', () => {
    registerFormErrorSummary(globalThis.Alpine, globalThis.Livewire);
    registerConfirmDialog(globalThis.Alpine, globalThis.Livewire);

    // Drop responses for components that are no longer on the page. A
    // wire:poll request can still be in flight when wire:navigate swaps the
    // page; Livewire 4 doesn't cancel it on teardown, so the late response
    // morphs the detached element and Alpine throws "x is not defined" for
    // every directive in it. Livewire re-checks isCancelled() right after
    // onSuccess, so cancelling here skips the merge, effects and morph.
    Livewire.interceptMessage(({ message, onSuccess }) => {
        onSuccess(() => {
            if (!message.component.el.isConnected) {
                message.cancel();
            }
        });
    });

    Livewire.interceptRequest(({ onError, onFailure }) => {
        onError(({ response, body, preventDefault }) => {
            const status = response?.status;

            if (status === 419) {
                preventDefault();

                // Unattended displays (the TV dashboard) opt in to reloading
                // themselves rather than being sent away to the landing page.
                if (document.documentElement.dataset.reloadOnSessionExpiry !== undefined) {
                    globalThis.location.reload();
                    return;
                }

                const fallback = '/?session_expired=1';
                let target = fallback;
                if (typeof body === 'string' && body.length > 0) {
                    try {
                        const payload = JSON.parse(body);
                        if (payload && typeof payload.redirect === 'string') {
                            target = payload.redirect;
                        }
                    } catch {
                        // 419 body wasn't JSON; fall through to the fallback.
                    }
                }
                globalThis.location.assign(target);
                return;
            }

            if (typeof status === 'number' && status >= 500) {
                preventDefault();
                globalThis.Livewire.dispatch('toast', [{
                    type: 'error',
                    title: 'Something went wrong on the server.',
                    description: 'Please try again in a moment. If this keeps happening, contact your administrator.',
                    timeout: 8000,
                }]);
            }
        });

        onFailure(() => {
            // Network failure / unreachable server. The new interceptor API
            // does not expose a preventDefault() here; the unhandledrejection
            // guard above keeps the console quiet.
            globalThis.Livewire.dispatch('toast', [{
                type: 'error',
                title: 'Can’t reach the server.',
                description: 'Check your connection and try again.',
                timeout: 8000,
            }]);
        });
    });
});
