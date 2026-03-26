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
