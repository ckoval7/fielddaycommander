<?php

/**
 * Regression: broadcasts were published to the public REVERB_HOST, where the
 * proxy only forwards the WebSocket path (/app) and the API path (/apps)
 * fell through to Laravel as a 404, so no broadcast ever reached clients.
 */
test('broadcasts publish to the local reverb server', function () {
    $options = config('broadcasting.connections.reverb.options');

    expect($options['host'])->toBe('127.0.0.1')
        ->and((int) $options['port'])->toBe((int) config('reverb.servers.reverb.port'))
        ->and($options['scheme'])->toBe('http')
        ->and($options['useTLS'])->toBeFalse();
});
