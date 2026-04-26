import './bootstrap';
import dashboardSortable from './components/dashboard-sortable';
import contactQueue from './components/contact-queue';
import flatpickrComponent from './flatpickr-init';
import { Chart, registerables } from 'chart.js/auto';

// Make Chart.js globally available for dynamic imports
Chart.register(...registerables);
globalThis.Chart = Chart;

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
    Alpine.data('flatpickr', flatpickrComponent);
});

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
    Livewire.interceptRequest(({ onError, onFailure }) => {
        onError(({ response, body, preventDefault }) => {
            const status = response?.status;

            if (status === 419) {
                preventDefault();
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
