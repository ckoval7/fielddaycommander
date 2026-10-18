<?php

namespace App\Support;

use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Protocols\Pusher\Exceptions\InvalidOrigin;
use Laravel\Reverb\Protocols\Pusher\Server;

class LocalNetworkPusherServer extends Server
{
    /**
     * Verify the origin of the connection.
     *
     * Reverb matches allowed_origins with Str::is wildcards, which cannot
     * express IP ranges safely ("192.168.*" would also match
     * "192.168.evil.example"). Origins on the local network are therefore
     * checked here with the same anchored patterns the app uses for trusted
     * hosts, after Reverb's own allowed_origins check.
     *
     * @throws InvalidOrigin
     */
    protected function verifyOrigin(Connection $connection): void
    {
        try {
            parent::verifyOrigin($connection);
        } catch (InvalidOrigin $exception) {
            $originHost = parse_url((string) $connection->origin(), PHP_URL_HOST);

            if (! LocalNetworkHosts::contains(is_string($originHost) ? $originHost : null)) {
                throw $exception;
            }
        }
    }
}
