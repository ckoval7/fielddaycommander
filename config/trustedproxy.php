<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Reverse proxies whose X-Forwarded-* headers are believed (client IP,
    | scheme, host). Laravel's TrustProxies middleware reads this key. Use a
    | comma-separated list of IPs/CIDRs, or "*" to trust every caller (only
    | when the app is never reachable except through your proxy).
    |
    | Defaults to loopback only: on a native install FrankenPHP talks to
    | browsers directly, so nothing else may vouch for a client's address.
    | Each deploy path sets its own value: Docker trusts Docker's networks
    | (docker-compose.yml) and Helm trusts the cluster's ingress ranges.
    | Trusting every private range would let anyone on the field LAN fake
    | their IP and get around login and station-mode rate limits.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Hosts
    |--------------------------------------------------------------------------
    |
    | Host header values the app accepts, so links built from the request
    | (password resets, invitations) cannot point at an attacker's domain.
    | The APP_URL host (and its subdomains) and local-network hosts are always
    | trusted: loopback, private and Tailscale/CGNAT IPv4, IPv6 ULA and
    | link-local, and mDNS *.local names (see App\Support\LocalNetworkHosts).
    | Add every other name or public IP users reach the site by as a
    | comma-separated list, or "*" to accept any host. While APP_URL is
    | localhost, any host is accepted.
    |
    */

    'hosts' => env('TRUSTED_HOSTS'),

];
