// SPDX-FileCopyrightText: Taylor Otwell
// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later AND MIT

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
        const useTLS = (reverbConfig.scheme || currentScheme) === 'https';

        globalThis.Echo = new Echo({
            broadcaster: 'reverb',
            key: reverbConfig.key,
            wsHost: reverbConfig.host || globalThis.location.hostname,
            wsPort: reverbConfig.port || currentPort,
            wssPort: reverbConfig.port || currentPort,
            forceTLS: useTLS,
            // pusher-js's 'ws' transport opens wss:// when forceTLS is set and
            // ws:// otherwise. 'wss' is only its fallback for non-TLS pages, so
            // enabling just 'ws' avoids spurious wss:// attempts on HTTP
            // installs, while enabling just 'wss' leaves TLS with no transport.
            enabledTransports: ['ws'],
        });
    }
} catch (error) {
    console.warn('Laravel Echo not configured:', error.message);
}
