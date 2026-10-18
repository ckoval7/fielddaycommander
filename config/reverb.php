<?php

// SPDX-FileCopyrightText: Taylor Otwell
// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later AND MIT

/*
 * Hosts allowed to open WebSocket connections, compared against the host of
 * the browser's Origin header (wildcards allowed, e.g. "*.example.org").
 * The APP_URL host and its subdomains, REVERB_HOST, TRUSTED_HOSTS and
 * REVERB_ALLOWED_ORIGINS are listed here. Local-network origins (private and
 * Tailscale IPs, IPv6 ULA/link-local, *.local) are accepted on top of this
 * list by App\Support\LocalNetworkPusherServer, using the same rules as
 * App\Support\LocalNetworkHosts. An unconfigured APP_URL (localhost) allows
 * any origin.
 */
$reverbAppUrlHost = strtolower((string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST));
$reverbListedHosts = static fn (mixed $value): array => array_filter(array_map(
    'trim',
    explode(',', strtolower((string) $value)),
));
$reverbAllowedOrigins = in_array($reverbAppUrlHost, ['', 'localhost', '127.0.0.1'], true)
    || in_array('*', $reverbListedHosts(env('TRUSTED_HOSTS')), true)
    ? ['*']
    : array_values(array_unique([
        $reverbAppUrlHost,
        '*.'.$reverbAppUrlHost,
        ...$reverbListedHosts(env('REVERB_HOST')),
        ...$reverbListedHosts(env('TRUSTED_HOSTS')),
        ...$reverbListedHosts(env('REVERB_ALLOWED_ORIGINS')),
    ]));

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Browser Connection
    |--------------------------------------------------------------------------
    |
    | Where browsers open the WebSocket. Every deploy path proxies Reverb's
    | /app path on the same address as the site, so by default the browser
    | connects to whatever address the page was loaded from. That keeps live
    | updates working by LAN IP, .local name or any other trusted host. Set
    | these only when Reverb is served from a different public address.
    |
    */

    'client' => [
        'host' => env('REVERB_CLIENT_HOST'),
        'port' => env('REVERB_CLIENT_PORT'),
        'scheme' => env('REVERB_CLIENT_SCHEME'),
    ],

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => $reverbAllowedOrigins,
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
            ],
        ],

    ],

];
