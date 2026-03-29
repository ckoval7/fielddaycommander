import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

globalThis.axios = axios;
globalThis.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Configure Laravel Echo if broadcasting is enabled
// For now, we'll set up Echo to connect to Reverb when it's available
// This prevents errors when Echo is not fully configured
globalThis.Pusher = Pusher;

try {
    // Read Reverb config from server-injected meta tag (runtime-configurable)
    const reverbMeta = document.querySelector('meta[name="reverb-config"]');
    const reverbConfig = reverbMeta ? JSON.parse(reverbMeta.content) : {};

    if (reverbConfig.key) {
        const currentPort = globalThis.location.port || (globalThis.location.protocol === 'https:' ? 443 : 80);
        const currentScheme = globalThis.location.protocol === 'https:' ? 'https' : 'http';

        globalThis.Echo = new Echo({
            broadcaster: 'reverb',
            key: reverbConfig.key,
            wsHost: reverbConfig.host || globalThis.location.hostname,
            wsPort: reverbConfig.port || currentPort,
            wssPort: reverbConfig.port || currentPort,
            forceTLS: (reverbConfig.scheme || currentScheme) === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    }
} catch (error) {
    console.warn('Laravel Echo not configured:', error.message);
}
