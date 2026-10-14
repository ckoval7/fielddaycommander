<?php

use Illuminate\Support\Facades\Config;

/**
 * Echo only starts when the layout's reverb-config meta carries an app key.
 * Demo mode must keep it: broadcast channels are scoped per visitor, so the
 * websocket no longer leaks one visitor's updates to another.
 */
test('layouts expose the reverb app key in demo mode', function (string $layout) {
    Config::set('demo.enabled', true);
    Config::set('reverb.apps.apps.0.key', 'demo-reverb-key');

    $html = view($layout, ['slot' => ''])->render();

    expect($html)->toContain('<meta name="reverb-config"')
        ->toContain('demo-reverb-key');
})->with([
    'app layout' => 'components.layouts.app',
    'guest layout' => 'components.layouts.guest',
]);
