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

// Graceful handling of failed Livewire XHR requests. We suppress Livewire's
// default "Page Expired" / Whoops modals and surface a toast (or a redirect,
// for 419) so the app degrades more gracefully when the session expires or
// the server is unhealthy.
document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, response, preventDefault }) => {
            if (status === 419) {
                preventDefault();
                const fallback = '/?session_expired=1';
                try {
                    response.clone().json().then((payload) => {
                        if (payload && typeof payload.redirect === 'string') {
                            globalThis.location.assign(payload.redirect);
                        } else {
                            globalThis.location.assign(fallback);
                        }
                    }).catch(() => globalThis.location.assign(fallback));
                } catch (e) {
                    console.error('Livewire 419 handler failed to parse response', e);
                    globalThis.location.assign(fallback);
                }
                return;
            }

            // 5xx responses and network failures (status 0) mean the server
            // is unhealthy or unreachable. Swap Livewire's default error
            // modal for a toast so the UI stays usable.
            if (status >= 500 || status === 0) {
                preventDefault();
                const isUnreachable = status === 0;
                globalThis.Livewire.dispatch('toast', [{
                    type: 'error',
                    title: isUnreachable
                        ? 'Can’t reach the server.'
                        : 'Something went wrong on the server.',
                    description: isUnreachable
                        ? 'Check your connection and try again.'
                        : 'Please try again in a moment. If this keeps happening, contact your administrator.',
                    timeout: 8000,
                }]);
            }
        });
    });
});
