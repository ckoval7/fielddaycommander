<?php

namespace App\Support;

class TrustedHosts
{
    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Build the trusted Host header patterns from configuration.
     *
     * The APP_URL host and its subdomains, local-network hosts (see
     * LocalNetworkHosts, which keeps LAN, Tailscale and mDNS access, Docker
     * health checks and Kubernetes probes working) and TRUSTED_HOSTS entries
     * are trusted. An empty list means every host is accepted.
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        $configured = trim((string) config('trustedproxy.hosts', ''));
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($configured === '*' || ! is_string($appHost) || $appHost === '' || $appHost === 'localhost') {
            return [];
        }

        $patterns = ['^(.+\.)?'.preg_quote($appHost).'$', ...LocalNetworkHosts::PATTERNS];

        $extraHosts = array_filter(array_map(trim(...), explode(',', $configured)));

        foreach ($extraHosts as $host) {
            $patterns[] = '^'.preg_quote(strtolower($host)).'$';
        }

        return array_values(array_unique($patterns));
    }
}
