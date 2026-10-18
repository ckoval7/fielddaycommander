<?php

/**
 * Browsers must open the live-update WebSocket on the address the page came
 * from, so LAN IPs, .local names and plain-HTTP installs keep working. The
 * server-side REVERB_HOST/PORT/SCHEME (public domain, 443/https defaults)
 * must not leak into the browser config unless REVERB_CLIENT_* overrides it.
 */
function reverbMetaConfig(string $layout): array
{
    $html = view($layout, ['slot' => ''])->render();

    preg_match('/<meta name="reverb-config" content="([^"]*)">/', $html, $matches);

    return json_decode(html_entity_decode($matches[1]), true);
}

test('browser websocket config defaults to the page address', function (string $layout) {
    config([
        'reverb.apps.apps.0.key' => 'test-key',
        'reverb.apps.apps.0.options.host' => 'fd.example.org',
        'reverb.apps.apps.0.options.port' => 443,
        'reverb.apps.apps.0.options.scheme' => 'https',
    ]);

    expect(reverbMetaConfig($layout))->toBe([
        'key' => 'test-key',
        'host' => null,
        'port' => null,
        'scheme' => null,
    ]);
})->with(['components.layouts.app', 'components.layouts.guest']);

test('browser websocket config honors explicit client overrides', function (string $layout) {
    config([
        'reverb.apps.apps.0.key' => 'test-key',
        'reverb.client' => ['host' => 'ws.example.org', 'port' => '8443', 'scheme' => 'https'],
    ]);

    expect(reverbMetaConfig($layout))->toMatchArray([
        'host' => 'ws.example.org',
        'port' => '8443',
        'scheme' => 'https',
    ]);
})->with(['components.layouts.app', 'components.layouts.guest']);
